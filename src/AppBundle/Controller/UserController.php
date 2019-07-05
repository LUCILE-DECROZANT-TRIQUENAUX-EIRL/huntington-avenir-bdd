<?php
/**
 * User controller
 */

namespace AppBundle\Controller;

use AppBundle\Entity\User;
use AppBundle\Service\Utils\RouteService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Form\FormError;

/**
 * User controller.
 *
 * @Route("user")
 */
class UserController extends Controller
{
    /**
     * Lists all user entities.
     * @return views
     * @Route(path="/", name="user_list", methods={"GET"})
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function listAction()
    {
        $em = $this->getDoctrine()->getManager();

        $users = $em->getRepository('AppBundle:User')->findAll();

        $deleteForms = [];
        foreach ($users as $user) {
            $deleteForm = $this->createDeleteForm($user);
            $deleteForms[$user->getId()] = $deleteForm->createView();
        }

        return $this->render('@App/User/list.html.twig', array(
            'users' => $users,
            'user_deletion_forms' => $deleteForms,
        ));
    }

    /**
     * Creates a new user entity.
     * @return views
     * @param Request $request The request.
     * @param UserPasswordEncoderInterface $passwordEncoder Encodes the password.
     * @param RouteService $routeService The route of the service.
     * @Route("/new", name="user_create", methods={"GET", "POST"})
     * @Security("has_role('ROLE_ADMIN')")
     */
    public function createAction(Request $request, UserPasswordEncoderInterface $passwordEncoder, RouteService $routeService)
    {
        // RouteService usage example
        $infos = $routeService->getPreviousRouteInfo();

        $user = new User();
        $form = $this->createForm('AppBundle\Form\UserType', $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $passwordEncoder->encodePassword($user, $user->getPlainPassword());
            $user->setPassword($password);

            $em = $this->getDoctrine()->getManager();
            $em->persist($user);
            $em->flush();

            $this->addFlash(
                    'success', sprintf('L\'utilisateurice <strong>%s</strong> a été créé.e', $user->getUsername())
            );

            return $this->redirectToRoute('user_show', array('id' => $user->getId()));
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            $this->addFlash(
                    'danger', sprintf('L\'utilisateurice <strong>%s</strong> n\'a pas pu être créé.e', $user->getUsername())
            );
        }

        // Check the referer to diplay in the view the good breadcrumb
        if ($infos['_route'] === 'administration_dashboard')
        {
            $from = 'administration';
        }
        else
        {
            $from = 'list';
        }

        return $this->render('@App/User/new.html.twig', array(
            'user' => $user,
            'from' => $from,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a user entity.
     * @return views
     * @param User $user The user to display.
     * @Route("/{id}", name="user_show", methods={"GET"})
     * @Security("has_role('ROLE_ADMIN') || (has_role('ROLE_INSCRIT_E') && (user.getId() == id))")
     */
    public function showAction(User $user)
    {
        $deleteForm = $this->createDeleteForm($user);

        return $this->render('@App/User/show.html.twig', array(
            'user' => $user,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Finds and displays history of a user editions
     * @return views
     * @param User $user The user to display.
     * @Route("/{id}/history", name="user_history", methods={"GET"})
     * @Security("has_role('ROLE_ADMIN') || (has_role('ROLE_INSCRIT_E') && (user.getId() == id))")
     */
    public function historyAction(User $user)
    {
        $deleteForm = $this->createDeleteForm($user);

        return $this->render('@App/User/history.html.twig', array(
            'user' => $user,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing user entity.
     * @return views
     * @param Request $request The request.
     * @param User $user The user to edit.
     * @param UserPasswordEncoderInterface $passwordEncoder Encodes the password.
     * @Route("/{id}/edit", name="user_edit", methods={"GET", "POST"})
     * @Security("has_role('ROLE_ADMIN') || (has_role('ROLE_INSCRIT_E') && (user.getId() == id))")
     */
    public function editAction(Request $request, User $user, UserPasswordEncoderInterface $passwordEncoder)
    {
        $deleteForm = $this->createDeleteForm($user);
        $editForm = $this->createForm('AppBundle\Form\UserGeneralDataType', $user);
        $editForm->handleRequest($request);
        $passwordForm = $this->createForm('AppBundle\Form\UserPasswordType', []);
        $passwordForm->handleRequest($request);

        // Submit change of general infos
        if ($editForm->isSubmitted() && $editForm->isValid())
        {
            $this->getDoctrine()->getManager()->flush();
            $this->addFlash(
                    'success', sprintf('Les informations ont bien été modifiées')
            );

            return $this->redirectToRoute('user_edit', ['id' => $user->getId()]);
        }

        // Submit change of password
        if ($passwordForm->isSubmitted())
        {
            $oldPassword = $user->getPassword();
            $plainOldPassword = $passwordForm['oldPassword']->getData();
            $plainPassword = $passwordForm['plainPassword']->getData();

            // If a password is entered and the old password typed in is correct
            if ($plainPassword !== null && password_verify($plainOldPassword,$oldPassword)) {
                $password = $passwordEncoder->encodePassword($user, $plainPassword);
                $user->setPassword($password);

                $this->getDoctrine()->getManager()->persist($user);
                $this->getDoctrine()->getManager()->flush();

                $this->addFlash(
                        'success', sprintf('Le mot de passe a bien été modifié')
                );
            }

            // Error message
            if (!password_verify($plainOldPassword,$oldPassword))
            {
                $passwordForm->get('oldPassword')->addError(new FormError('L\'ancien mot de passe ne correspond pas'));
            }

        }

        return $this->render('@App/User/edit.html.twig', array(
            'user' => $user,
            'edit_form' => $editForm->createView(),
            'password_form' => $passwordForm->createView(),
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Deletes a user entity.
     * @return views
     * @param User $user The user to delete.
     * @param Request $request The request.
     * @Route("/{id}", name="user_delete", methods={"DELETE"})
     * @Security("has_role('ROLE_ADMIN') || (has_role('ROLE_INSCRIT_E') && (user.getId() == id))")
     */
    public function deleteAction(Request $request, User $user)
    {
        $form = $this->createDeleteForm($user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $username = $user->getUsername();
            $em = $this->getDoctrine()->getManager();
            $em->remove($user);
            $em->flush();
            $this->addFlash(
                    'success', sprintf('L\'utilisateurice <strong>%s</strong> a bien été supprimé.e.', $username)
            );
        }

        return $this->redirectToRoute('user_list');
    }

    /**
     * Creates a form to delete a user entity.
     * @return views
     * @param User $user The user entity.
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm(User $user)
    {
        return $this->createFormBuilder()
        ->setAction($this->generateUrl('user_delete', array('id' => $user->getId())))
        ->setMethod('DELETE')
        ->getForm()
        ;
    }
}
