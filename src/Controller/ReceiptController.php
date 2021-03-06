<?php

namespace App\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\MimeType\FileinfoMimeTypeGuesser;
use Symfony\Component\Messenger\MessageBusInterface;
use Dompdf\Dompdf;
use App\Service\ReceiptService;
use App\Entity\Payment;
use App\Entity\Receipt;
use App\Entity\ReceiptsGroupingFile;
use App\Entity\ReceiptsFromYearGroupingFile;
use App\Entity\ReceiptsFromTwoDatesGroupingFile;
use App\Message\GenerateReceiptFromYearMessage;
use App\Message\GenerateReceiptFromTwoDatesMessage;
use App\FormDataObject\GenerateTaxReceiptFromYearFDO;
use App\FormDataObject\GenerateTaxReceiptFromTwoDatesFDO;
use App\Form\GenerateTaxReceiptFromYearType;
use App\Form\GenerateTaxReceiptFromTwoDatesType;

/**
 * @Route(path="/{_locale}/receipt", requirements={"_locale"="en|fr"})
 */
class ReceiptController extends AbstractController
{
    /**
     * @return views
     * @param Request $request The request.
     * @Route("/", name="receipt_list", requirements={"_locale"="en|fr"})
     * @Security("is_granted('ROLE_GESTION')")
     */
    public function listAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $annualReceiptsFiles = $em->getRepository(ReceiptsFromYearGroupingFile::class)
                ->findAll();

        $betweenTwoDatesReceiptsFiles = $em->getRepository(ReceiptsFromTwoDatesGroupingFile::class)
                ->findAll();

        return $this->render('Receipt/list.html.twig', [
            'generatedAnnualReceipts' => $annualReceiptsFiles,
            'generatedBetweenTwoDatesReceipts' => $betweenTwoDatesReceiptsFiles,
            'generatedParticularReceipts' => null,
        ]);
    }

    /**
     * @return views
     * @param Request $request The request.
     * @Route("/generate/from-year", name="receipt_generate_from_year", requirements={"_locale"="en|fr"})
     * @Security("is_granted('ROLE_GESTION')")
     */
    public function generateFromYearAction(
        Request $request,
        MessageBusInterface $messageBus,
        ReceiptService $receiptService,
        TranslatorInterface $translator
    )
    {
        // Entity manager
        $em = $this->getDoctrine()->getManager();

        // Find fiscal years for which there is receipts to generate
        $availableYears = $em->getRepository(Receipt::class)->findAvailableYears();

        // Setup options for each fiscal year
        $availableYearsData = [];
        foreach ($availableYears as $availableYear)
        {
            // Check if a file is currently generated
            $filesBeingCurrentlyGenerated = $em->getRepository(ReceiptsFromYearGroupingFile::class)
                    ->findByGenerationInProgress($availableYear);
            if (count($filesBeingCurrentlyGenerated) > 0)
            {
                $dataIsUnderGeneration = [
                    'data-is-under-generation' => 'true',
                ];
            }
            else
            {
                $dataIsUnderGeneration = [
                    'data-is-under-generation' => 'false',
                ];
            }

            // Get the last generation date if it exists
            $lastFileGenerated = $em->getRepository(ReceiptsFromYearGroupingFile::class)
                    ->findLastGenerated($availableYear);
            if (!empty($lastFileGenerated))
            {
                $dataLastGenerationDate = [
                    'data-last-generation-date' => $lastFileGenerated->getReceiptsGenerationBase()->getGenerationDateEnd()->format('Y-m-d\TH:i:s'),
                ];
            }
            else
            {
                $dataLastGenerationDate = [
                    'data-last-generation-date' => 'false',
                ];
            }
            $availableYearsData[$availableYear] = array_merge(
                $dataIsUnderGeneration,
                $dataLastGenerationDate
            );
        }

        // Creating an empty FDO
        $generateTaxReceiptFromYearFDO = new GenerateTaxReceiptFromYearFDO();

        // From creation
        $generateFromYearForm = $this->createForm(
            GenerateTaxReceiptFromYearType::class,
            $generateTaxReceiptFromYearFDO,
            [
                'availableYears' => $availableYears,
                'availableYearsData' => $availableYearsData,
            ]
        );

        $generateFromYearForm->handleRequest($request);

        // Submit
        if ($generateFromYearForm->isSubmitted() && $generateFromYearForm->isValid())
        {
            $year = $generateTaxReceiptFromYearFDO->getYear();

            // Creating the database log
            $receiptGenerationDate = new \DateTime();
            $receiptsGroupingFile = new ReceiptsGroupingFile();
            $receiptsGroupingFile->setGenerationDateStart($receiptGenerationDate);
            $receiptsGroupingFile->setGenerator($this->getUser());
            $receiptsFromYearGroupingFile = new ReceiptsFromYearGroupingFile();
            $receiptsFromYearGroupingFile->setYear($year);
            $receiptsFromYearGroupingFile->setReceiptsGenerationBase($receiptsGroupingFile);

            // Save that the file is being generated
            $em->persist($receiptsGroupingFile);
            $em->persist($receiptsFromYearGroupingFile);
            $em->flush();

            $messageBus->dispatch(
                    new GenerateReceiptFromYearMessage(
                            $receiptsFromYearGroupingFile->getId(),
                            $this->getUser()->getId()
            ));

            $this->addFlash(
                    'success', $translator->trans('Génération du PDF en cours...')
            );

            return $this->redirectToRoute('receipt_list');
        }

        return $this->render('Receipt/generate-from-year.html.twig', [
            'from_year_form' => $generateFromYearForm->createView(),
        ]);
    }

    /**
     * @return views
     * @param Request $request The request.
     * @Route("/generate/from-two-dates", name="receipt_generate_from_two_dates", requirements={"_locale"="en|fr"})
     * @Security("is_granted('ROLE_GESTION')")
     */
    public function generateFromTwoDatesAction(
        Request $request,
        MessageBusInterface $messageBus,
        ReceiptService $receiptService,
        TranslatorInterface $translator
    )
    {
        // Entity manager
        $em = $this->getDoctrine()->getManager();

        // Creating an empty FDO
        $generateTaxReceiptFromTwoDatesFDO = new GenerateTaxReceiptFromTwoDatesFDO();

        // From creation
        $generateFromTwoDatesForm = $this->createForm(
            GenerateTaxReceiptFromTwoDatesType::class,
            $generateTaxReceiptFromTwoDatesFDO
        );

        $generateFromTwoDatesForm->handleRequest($request);

        // Submit
        if ($generateFromTwoDatesForm->isSubmitted() && $generateFromTwoDatesForm->isValid())
        {
            // Getting form data
            $from = $generateTaxReceiptFromTwoDatesFDO->getFrom();
            $to   = $generateTaxReceiptFromTwoDatesFDO->getTo();


            // Check if a file is currently generated
            $filesBeingCurrentlyGenerated = $em->getRepository(ReceiptsFromTwoDatesGroupingFile::class)
                ->findByGenerationInProgress($from, $to);

            // If files are being generated
            if (count($filesBeingCurrentlyGenerated) > 0)
            {
                // We show a warning message and do nothing more
                $this->addFlash(
                        'danger', $translator->trans('Un PDF est déjà en cours de génération pour ces dates-là. Veuillez patienter ou changer de période.')
                );
            }
            else
            {
                // Creating the database log
                $receiptGenerationDate = new \DateTime();
                $receiptsGroupingFile = new ReceiptsGroupingFile();
                $receiptsGroupingFile->setGenerationDateStart($receiptGenerationDate);
                $receiptsGroupingFile->setGenerator($this->getUser());
                $receiptsFromTwoDatesGroupingFile = new ReceiptsFromTwoDatesGroupingFile();
                $receiptsFromTwoDatesGroupingFile->setDateFrom($from);
                $receiptsFromTwoDatesGroupingFile->setDateTo($to);
                $receiptsFromTwoDatesGroupingFile->setReceiptsGenerationBase($receiptsGroupingFile);

                // Save that the file is being generated
                $em->persist($receiptsGroupingFile);
                $em->persist($receiptsFromTwoDatesGroupingFile);
                $em->flush();

                $messageBus->dispatch(
                        new GenerateReceiptFromTwoDatesMessage(
                                $receiptsFromTwoDatesGroupingFile->getId(),
                                $this->getUser()->getId()
                ));

                $this->addFlash(
                        'success', $translator->trans('Génération du PDF en cours...')
                );

                return $this->redirectToRoute('receipt_list');
            }
        }

        return $this->render('Receipt/generate-from-two-dates.html.twig', [
            'from_two_dates_form' => $generateFromTwoDatesForm->createView(),
        ]);
    }

    /**
     * @return BinaryFileResponse
     * @param Request $request The request.
     * @Route("/download-grouped-pdf/{id}", name="download_grouped_receipts_pdf", requirements={"_locale"="en|fr"})
     * @Security("is_granted('ROLE_GESTION')")
     */
    public function downloadGroupedPdfAction(ReceiptsGroupingFile $file)
    {
        $pdfFolderPath = $this->getParameter('kernel.project_dir') . '/pdf/';
        $filename = $file->getFilename();

        $response = new BinaryFileResponse($pdfFolderPath . $filename);

        // Set mime type of the file to pdf
        $mimeTypeGuesser = new FileinfoMimeTypeGuesser();
        $response->headers->set('Content-Type', 'application/pdf');

        // Will make the browser directly download and not try to open the pdf
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
    }

    /**
     * @param ReceiptsGroupingFile $file
     * @return Response
     * @Route("/check-generation-grouped-pdf/{id}", name="check_generation_grouped_receipts_pdf", requirements={"_locale"="en|fr"})
     * @Security("is_granted('ROLE_GESTION')")
     */
    public function checkGenerationGroupedPdfAction(ReceiptsGroupingFile $file)
    {
        $response = new Response();
        $response->setContent(json_encode([
            'isGenerationComplete' => !empty($file->getGenerationDateEnd()),
            'downloadUrl' => $this->generateUrl('download_grouped_receipts_pdf', ['id' => $file->getId()]),
        ]));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

}
