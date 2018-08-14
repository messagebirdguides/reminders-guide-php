# SMS Appointment Reminders
### ⏱ 15 min build time

## Why build SMS appointment reminders? 

Booking appointments online from a website or mobile app is quick and easy. Customers just have to select their desired date and time, enter their personal details and hit a button. The problem, however, is that easy-to-book appointments are often just as easy to forget.

For appointment-based services, no-shows are annoying and costly because of the time and revenue lost waiting for a customer instead of serving them, or another customer. Timely SMS reminders act as simple and discrete nudges, which can go a long way in the prevention of costly no-shows.

## Getting Started

In this MessageBird Developer Guide, we'll show you how to use the MessageBird SMS messaging API to build an SMS appointment reminder application in PHP. This sample application represents the order website of a fictitious online beauty salon called _BeautyBird_. To reduce the growing number of no-shows, BeautyBird now collects appointment bookings through a form on their website and schedules timely SMS reminders to be sent out three hours before the selected date and time.

To run the sample application, you need to have PHP installed on your machine. If you're using a Mac, PHP is already installed. For Windows, you can [get it from windows.php.net](https://windows.php.net/download/). Linux users, please check your system's default package manager. You also need Composer, which is available from [getcomposer.org](https://getcomposer.org/download/), to install the [MessageBird SDK for PHP](https://github.com/messagebird/php-rest-api) and other dependencies.

You can either clone the [MessageBird Developer Guides GitHub repository](https://github.com/messagebirdguides/reminders-guide-php) or download and extract a ZIP archive.

Then, open a console pointed at the directory into which you've placed the sample application and run the following command:

````bash
composer install
````

Apart from the MessageBird SDK, Composer will install a few additional libraries: the [Slim framework](https://packagist.org/packages/slim/slim), the [Twig templating engine](https://packagist.org/packages/slim/twig-view), and the [Dotenv configuration library](https://packagist.org/packages/vlucas/phpdotenv). Using these libraries we keep our controller, view, and configuration nicely separated without having to set up a full-blown framework like Laravel or Symfony.

## Configuring the MessageBird SDK

The SDK is listed as a dependency in `composer.json`:

````json
{
    "require" : {
        "messagebird/php-rest-api" : "^1.9.4"
        ...
    }
}
````

An application can access the SDK, which is made available through Composer autoloading, by creating an instance of the `MessageBird\Client` class. The constructor takes a single argument, your API key. For frameworks like Slim it makes sense to add the SDK to the dependency injection container like this:

````php
// Load and initialize MesageBird SDK
$container['messagebird'] = function() {
    return new MessageBird\Client(getenv('MESSAGEBIRD_API_KEY'));
};
````

As it's a bad practice to keep credentials in the source code, we load the API key from an environment variable using `getenv()`. To make the key available in the environment variable we need to initialize Dotenv and then add the key to a `.env` file.

Apart from `MESSAGEBIRD_API_KEY` we use two other environment variables as part of the application's configuration; the `COUNTRY_CODE`, which will later help us understand the user's phone number, and the `TIMEZONE`, so we can make date and time calculations within the correct timezone.

You can copy the `env.example` file provided in the repository to `.env` and then add your API key and values for the other configuration options like this:

````env
MESSAGEBIRD_API_KEY=YOUR-API-KEY
COUNTRY_CODE=NL
TIMEZONE=Europe/Berlin
````

You can retrieve or create an API key from the [API access (REST) tab](https://dashboard.messagebird.com/en/developers/access) in the _Developers_ section of your MessageBird account.

## Collecting User Input

To send SMS messages to users, you need to collect their phone number as part of the booking process. We have created a sample form that asks the user for their name, desired treatment, number, date and time. For HTML forms it's recommended to use `type="tel"` for the phone number input. You can see the template for the complete form in the file `views/home.html.twig` and the route that drives it is defined as `$app->get('/')` in `index.php`.

## Storing Appointments & Scheduling Reminders

The user's input is sent to the route `$app->post('/book')` defined in `index.php`. The implementation covers the following steps:

### Step 1: Check their input

Validate that the user has entered a value for every field in the form. For easier access to input parameters, we assign the parsed request body to an array variable called `$input`.

### Step 2: Check the appointment date and time

Confirm that the date and time are valid and at least three hours and five minutes in the future. BeautyBird won't take bookings on shorter notice. Also, since we want to schedule reminders three hours before the treatment, anything else doesn't make sense from a testing perspective. We use PHP's `DateTime` object for this:

````php
    // Check if date/time is correct and at least 3:05 hours in the future
    $earliestPossibleDT = new DateTime('+ 3 hour 5 minute');
    $appointmentDT = new DateTime($input['date'].' '.$input['time']);
    if ($appointmentDT < $earliestPossibleDT) {
        // If not, show an error
        // ...
````

### Step 3: Check their phone number

Check whether the phone number is correct. This can be done with the [MessageBird Lookup API](https://developers.messagebird.com/docs/lookup#lookup-request), which takes a phone number entered by a user, validates the format and returns information about the number, such as whether it is a mobile or fixed line number. This API doesn't enforce a specific format for the number but rather understands a variety of different variants for writing a phone number, for example using different separator characters between digits, giving your users the flexibility to enter their number in various ways. On the SDK object, which we can get from the container through `$this->messagebird`, we can call `lookup->read()`:

````php
    // Check if phone number is valid
    try {
        $lookupResponse = $this->messagebird->lookup->read(
            $input['number'], getenv('COUNTRY_CODE'));
        // ...
````

The function takes two arguments, the phone number and a default country code. Providing the latter enables users to supply their number in a local format, without the country code. We're reading it from the environment variable we defined earlier.

There are four different cases we need to handle as the result of the Lookup API. First, there's two cases that are handled through exceptions:
- MessageBird was unable to parse the phone number because the user has entered an invalid value. For this case we're using a `catch`-block to intercept a `MessageBird\Exceptions\RequestException`.
- Another error occurred in the API. For this case there's a second `catch`-block to intercept any generic `Exception`.

````php
    } catch (MessageBird\Exceptions\RequestException $e) {
        // A RequestException typically indicates that the phone number has an unknown format
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "You need to enter a valid phone number!" ]));
    } catch (Exception $e) {
        // Some other error occurred
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "Something went wrong while checking your phone number!" ]));
    }
````

If no exception was caught, our application calls `$lookupResponse->getType()` and checks the value:
- If it is `mobile`, we can continue.
- If it is any other value, return an error.

````php
    // Check type
    if ($lookupResponse->getType() != 'mobile') {
        // The number lookup was successful but it is not a mobile number
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "You have entered a valid phone number, but it's not a mobile number! Provide a mobile number so we can contact you via SMS." ]));
    }
````

### Step 4: Schedule the reminder

We determine the date and time for sending a reminder:

````php
    // Schedule reminder 3 hours prior to the treatment
    $reminderDT = (clone $appointmentDT)->modify('- 3 hour');
````

Then, we need to prepare a `MessageBird\Objects\Message` object that we can send through the API:

````php
    // Create message object
    $message = new MessageBird\Objects\Message;
    $message->originator = 'BeautyBird';
    $message->recipients = [ $lookupResponse->getPhoneNumber() ]; // normalized phone number from lookup request
    $message->scheduledDatetime = $reminderDT->format('c');
    $message->body = $input['name'] . ", here's a reminder that you have a " . $input['treatment'] . " scheduled for " . $appointmentDT->format('H:i') . ". See you soon!";
````

Let's break down the attributes of this object:
- `originator`: The sender ID. You can use a mobile number here, or an alphanumeric ID, like in the example.
- `recipients`: An array of phone numbers. We just need one number, and we're using the normalized number returned from the Lookup API instead of the user-provided input.
- `scheduledDatetime`: This instructs MessageBird not to send the message immediately but at a given timestamp, which we've defined previously. Using `format('c')` method we make sure the API can read this timestamp correctly.
- `body`: The friendly text for the message.

Next, we can send this object with the `messages->create()` method in the MessageBird SDK, contained in a try-catch-block to report errors:

````php
    // Send scheduled message with MessageBird API
    try {
        $this->messagebird->messages->create($message);
    } catch (Exception $e) {
        return $this->view->render($response, 'home.html.twig',
            array_merge($input, [ 'error' => "Error occured while sending message!" ]));
    }
````

### Step 5: Store the appointment and confirm

We're almost done! In a real application, you would have to store the appointment in a database, but we've omitted this part for the sample.

Finally, we show a confirmation page, which is defined in `views/confirm.html.twig`.

## Testing the Application

You can use PHP's built-in web server to test the application. Enter the following command on the console to start it:

````bash
php -S 0.0.0.0:8080 index.php
````

Then, point your browser at http://localhost:8080/ to see the form and schedule your appointment! If you've used a live API key, a message will arrive to your phone three hours before the appointment! But don't actually leave the house, this is just a demo :)

## Nice work!

You now have a running SMS appointment reminder application!

You can now use the flow, code snippets and UI examples from this tutorial as an inspiration to build your own SMS reminder system. Don't forget to download the code from the [MessageBird Developer Guides GitHub repository](https://github.com/messagebirdguides/reminders-guide-php).

## Next steps

Want to build something similar but not quite sure how to get started? Please feel free to let us know at support@messagebird.com, we'd love to help!
