REM call like this from your PHP script to execute SMTP queue processor in the background
REM   	$cmd = 'T:\path\to\run_windows.bat';
REM		pclose(popen('start /B '.$cmd, 'r'));
REM
t:
cd T:\xampp\htdocs\class_email
php -f smtp_queue_processor.php > win_cmd.log