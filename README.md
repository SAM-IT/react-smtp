# react-smtp
SMTP Server based on ReactPHP

It supports many concurrent STMP connections.

[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/SAM-IT/react-smtp/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/SAM-IT/react-smtp/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/SAM-IT/react-smtp/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/SAM-IT/react-smtp/?branch=master)
[![Build Status](https://scrutinizer-ci.com/g/SAM-IT/react-smtp/badges/build.png?b=master)](https://scrutinizer-ci.com/g/SAM-IT/react-smtp/build-status/master)


# Usage example

## Server:

````
include 'vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$server = new \SamIT\React\Smtp\Server($loop);
$server->listen(8025);
$server->on('message', function($from, array $recipients, \SamIT\React\Smtp\Message $message, \SamIT\React\Smtp\Connection $connection) {
    var_dump($message->getHeaders());
    var_dump($message->getBody()->getSize());
});
$loop->run();

````

### Example client using PHPMailer:
````

include 'vendor/autoload.php';

$mail = new PHPMailer();

$mail->isSMTP();
$mail->Host = 'localhost';
$mail->Port = 8025;
$mail->SMTPDebug = true;

$mail->setFrom('from@example.com', 'Mailer');
$mail->addAddress('joe@example.net', 'Joe User');     // Add a recipient
$mail->addAddress('ellen@example.com');               // Name is optional
$mail->addReplyTo('info@example.com', 'Information');
$mail->addCC('cc@example.com');
$mail->addBCC('bcc@example.com');

$mail->Subject = 'Here is the subject';
$mail->Body    = 'This is the HTML message body <b>in bold!</b>';
$mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

if(!$mail->send()) {
    echo 'Message could not be sent.';
    echo 'Mailer Error: ' . $mail->ErrorInfo;
} else {
    echo 'Message has been sent';
}
````