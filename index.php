<?php

require_once "vendor/autoload.php";

// Create app
$app = new Slim\App;

// Load configuration with dotenv
$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

// Get container
$container = $app->getContainer();

// Set timezone
date_default_timezone_set(getenv('TIMEZONE'));

// Register Twig component on container to use view templates
$container['view'] = function() {
    return new Slim\Views\Twig('views');
};

// Load and initialize MesageBird SDK
$container['messagebird'] = function() {
    return new MessageBird\Client(getenv('MESSAGEBIRD_API_KEY'));
};

// Display booking homepage
$app->get('/', function($request, $response) {
    // On the form, We're showing a default appointment
    // time 3:10 hours from now to simplify testing input
    $defaultDT = new DateTime('+ 3 hour 10 minute');
    return $this->view->render($response, 'home.html.twig',
        [
            'date' => $defaultDT->format('Y-m-d'),
            'time' => $defaultDT->format('H:i')
        ]);
});

// Process an incoming booking
$app->post('/book', function($request, $response) {
    
    // Check if user has provided input for all form fields
    $input = $request->getParsedBody();
    if (!isset($input['name']) || !isset($input['treatment'])
        || !isset($input['number']) || !isset($input['date'])
        || !isset($input['time']) || $input['name'] == ''
        || $input['treatment'] == '' || $input['number'] == ''
        || $input['date'] == '' || $input['time'] == '') {
            // If not, show an error
            return $this->view->render($response, 'home.html.twig',
                array_merge($input, [ 'error' => "Please fill all required fields!" ]));
        }

    // Check if date/time is correct and at least 3:05 hours in the future
    $earliestPossibleDT = new DateTime('+ 3 hour 5 minute');
    $appointmentDT = new DateTime($input['date'].' '.$input['time']);
    if ($appointmentDT < $earliestPossibleDT) {
        // If not, show an error
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "You can only book appointments that are at least 3 hours in the future!" ]));
    }

    // Check if phone number is valid
    try {
        $lookupResponse = $this->messagebird->lookup->read(
            $input['number'], getenv('COUNTRY_CODE'));
    } catch (MessageBird\Exceptions\RequestException $e) {
        // A RequestException typically indicates that the phone number has an unknown format
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "You need to enter a valid phone number!" ]));
    } catch (Exception $e) {
        // Some other error occurred
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "Something went wrong while checking your phone number!" ]));
    }

    // Check type
    if ($lookupResponse->getType() != 'mobile') {
        // The number lookup was successful but it is not a mobile number
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "You have entered a valid phone number, but it's not a mobile number! Provide a mobile number so we can contact you via SMS." ]));
    }

    // Everything OK

    // Schedule reminder 3 hours prior to the treatment
    $reminderDT = (clone $appointmentDT)->modify('- 3 hour');

    // Create message object
    $message = new MessageBird\Objects\Message;
    $message->originator = 'BeautyBird';
    $message->recipients = [ $lookupResponse->getPhoneNumber() ]; // normalized phone number from lookup request
    $message->scheduledDatetime = $reminderDT->format('c');
    $message->body = $input['name'] . ", here's a reminder that you have a " . $input['treatment'] . " scheduled for " . $appointmentDT->format('H:i') . ". See you soon!";

    // Send scheduled message with MessageBird API
    try {
        $this->messagebird->messages->create($message);
    } catch (Exception $e) {
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "Error occured while sending message!" ]));
    }

    // Create and persist appointment object
    $appointment = [
        'name' => $input['name'],
        'treatment' => $input['treatment'],
        'number' => $input['number'],
        'appointmentDT' => $appointmentDT->format('Y-m-d H:i'),
        'reminderDT' => $reminderDT->format('Y-m-d H:i')
    ];

    // ***
    // For the simplicity of this demo we have omitted persistence.
    // You can add your database logic here.
    // ***

    // Render confirmation page
    return $this->view->render($response, 'confirm.html.twig', $appointment);
});

// Start the application
$app->run();