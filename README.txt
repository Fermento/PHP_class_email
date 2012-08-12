class_email.php 
http://www.kaisersoft.net/t.php?phpemail


This is a PHP 5 class to send e-mails from web or command line scripts (CLI) via an external SMTP server like smtp.gmail.com. 
 It has been designed to be a replacement for the built in PHP mail() function by accepting the same input for sending e-mails. This makes it possible to implement the class with only minor modifications to your existing code.
 The class uses UTF-8 + base64 encoding to ensure that non-ASCII characters are displayed properly. It also supports encryption via STARTTLS, tls:// or ssl:// and CRAM-MD5, PLAIN or LOGIN authentication.

 This class is designed for business use where you want all outgoing e-mails to pass through the e-mail servers of your company.



 The class opens a tcp socket to the smtp server and will communicate with it just like a simple e-mail client would.
 I have tested this class on multiple Linux systems with the PHP5 packages provided by the distributions and on Windows XP/7/2008 with the latest XAMPP release. The class appears to be working on any of them so I am releasing it to the public.
 Please let me know if you are using the class in your project and please don't forget to send me any improvement ideas or bug fixes.

 Here is some sample code for the class.
<?PHP
require_once 'class_email.php';
$e = new email();

//First value is the URL of your server, the second the port number
$e->set_server( 'smtp.localhost.local', 587);
//First value is your username, then your password
$e->set_auth('user@localhost.local', 'YourPasswordHere');
//Set the "From" setting for your e-mail. The Name will be base64 encoded
$e->set_sender( 'Your Name here Ää, Öö, Üü', 'support@localhost.local' );

//for one recipient
$send_to = 'foo@localhost.local';
//you may also specify multiple recipients by creating an array like this:
$send_to = array('foo1@localhost.local', 'foo2@localhost.local', 'foo3@localhost.local');

$subject = 'This is a test subject Ää, Öö, Üü';
$body = "This is the test body of the message\r\nIt may contain special characters: Ää, Öö, Üü\r\n";
if( $e->mail($send_to, $subject, $body) == true )
{
  //message was received by the smtp server
  //['last'] tends to contain the queue id so I like to save that string in the database
  echo 'last: '.htmlspecialchars($e->srv_ret['last']).'';
}else{
  //something went wrong
  echo 'all: '.nl2br(htmlspecialchars($e->srv_ret['all'])).'';
  echo 'full:'.nl2br(htmlspecialchars($e->srv_ret['full'])).'';
}
?>
 Once you call $e->mail() you can use the $e->srv_ret[] array to get some debugging information.
 * ['last'] contains the last message from the smtp server or this class.
 * ['all'] contains everything the server sent to this class.
 * ['full'] contains everything the class sent to the server and the response

 The full log is useful if you are having trouble making a connection because you will know at which point the error occurs.
 The output will vary depending on the e-mail server configuration or version but it tends to be:
last: 250 2.0.0 Ok: queued as 167D3C2031E1

all:
220 mx.google.com ESMTP l26sm7962357fad.17
250-mx.google.com at your service, [188.105.199.153]
250-SIZE 35882577
250-8BITMIME
250-STARTTLS
250 ENHANCEDSTATUSCODES
220 2.0.0 Ready to start TLS
.... and so on


full:
notice: will attempt to switch to a tls secured connection after EHLO
notice: connected to server
recieved: 220 mx.google.com ESMTP l26sm7983346fad.17
notice: checking expected value 220 against the server response of 220 -- PASSED!
sent: EHLO your.rdns.hostname
received: 250-mx.google.com at your service, [188.105.199.153]
250-SIZE 35882577
250-8BITMIME
250-STARTTLS
250 ENHANCEDSTATUSCODES
notice: checking expected value 250 against the server response of 250 -- PASSED!
sent: STARTTLS
received: 220 2.0.0 Ready to start TLS
notice: checking expected value 220 against the server response of 220 -- PASSED!
notice: tls is now enabled
notice: sending EHLO again but this time encrypted
... and so on

 As you can see it is simple to send a message but it provides you with debug information when stuff is not working as expected.
 There are a few more methods explained in the included readme but the default settings should work on most servers.
 Please keep in mind that the communication with an external SMTP server will take a few seconds so it is a good idea to queue messages before sending them.
 I do this with a cronjob or a Windows Task executing "php -f queue_script.php" every few minutes and it works great!