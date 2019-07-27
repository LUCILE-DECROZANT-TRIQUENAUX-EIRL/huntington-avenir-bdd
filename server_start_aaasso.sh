### Function definition ###

# Will ask user input to generate the database_url
ask_user_input() {
    echo 'Please enter the following information.'

    # Ask and read database_host with default value 127.0.0.1
    read -p 'Database host [127.0.0.1] : ' database_host
    database_host=${database_host:-'127.0.0.1'}

    # Ask and read database_port with default value 3306
    read -p 'Database port [3306] : ' database_port
    database_port=${database_port:-3306}

    # Ask and read database_user, no default value
    read -p 'Database user : ' database_user

    # Ask and read database_password with no default value
    read -sp 'Database password : ' database_password
    # Since the input is hidden for security reasons, we need to write to the new line
    echo

    if [ $env = "test" ]
    then
        # Ask and read database_name with default value erp-asso-test
        read -p 'Database name [erp-asso-test] : ' database_name
        database_name=${database_name:-erp-asso-test}
    else
        # Ask and read database_name with default value erp-asso
        read -p 'Database name [erp-asso] : ' database_name
        database_name=${database_name:-erp-asso}
    fi

    # Concatening previous data to form the database connection string (also named url) for doctrine
    database_url="mysql://${database_user}:${database_password}@${database_host}:${database_port}/${database_name}"
}

# Will populate the phpunit.xml file with database_url
# Call this in test env only
setup_phpunit_file() {
    # We manage the case when there is no phpunit.xml file by copying the .dist
    if [ ! -f "phpunit.xml" ]
    then
        cp phpunit.xml.dist phpunit.xml
    fi

    # Creating vars for more visibility
    line_to_search="<env name=\\\"DATABASE_URL\\\".*"
    line_to_replace="<env name=\\\"DATABASE_URL\\\" value=\\\"${database_url}\\\" />"

    # Replacing the database url line with actual user input
    sed -i "s#${line_to_search}#${line_to_replace}#g" ./phpunit.xml
}

# Will create a .env.local_for_{env} file
setup_env_file() {
    # Asking user DB informations
    ask_user_input

    # Puting the file name in a var according to the env var, for more lisibility
    env_file_name=".env.local_for_${env}"

    # Creation the file
    touch $env_file_name

    # Content is writen in the file
    echo "###> doctrine/doctrine-bundle ###" >> $env_file_name
    echo "DATABASE_URL=$database_url" >> $env_file_name
    echo "###< doctrine/doctrine-bundle ###" >> $env_file_name
    echo "" >> $env_file_name
    echo "###> symfony/swiftmailer-bundle ###" >> $env_file_name
    echo "MAILER_URL=null://localhost" >> $env_file_name
    echo "###< symfony/swiftmailer-bundle ###" >> $env_file_name

    # If we are in test env, we are also modifying the phpunit.xml file
    if [ $env = "test" ]
    then
        setup_phpunit_file
    fi
}

# Puting the given param in a var
env=$1

if [ "$env" != "dev" ] && [ "$env" != "test" ]
then
    echo 'Unknown environment. Exiting the script.'
    exit 2
fi

# If there is no env file we will write one from user input
# But also the phpunit.xml file in test env
if [ ! -f ".env.local_for_${env}" ]
then
    echo "Since you have no database configuration file for ${env} environnement we will create one."
    setup_env_file
# If there is an env file, we also check on the phpunit.xml file
# In test env only
elif [ ! -f "phpunit.xml" ] && [ "$env" = "test" ]
then
    echo "Since you have no phpunit.xml file we will create one."
    ask_user_input
    setup_phpunit_file
fi

# If the .env.local file already exist
if [ -f ".env.local" ]
then
    # We are removig it
    rm .env.local
fi

# Making a new with the right parameters (dev or test depending on user input)
cp ".env.local_for_${env}" .env.local

# Starting the symfony server with the newly created env file
symfony server:start