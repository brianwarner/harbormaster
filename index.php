<?php

/*
* Copyright Brian Warner
*
* Harbormaster uses real-time parking information from Cleveland State
* University to notify a commuter which garage has the best availability.
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*
* SPDX-License-Identifier:  Apache-2.0
*/

// Make sure PHPMailer is accessible

if (!file_exists("PHPMailer/src/PHPMailer.php")) {
	echo '<p>ERROR: You need to clone PHPMailer into your Harbormaster directory.</br>
	<pre>$ git clone https://github.com/PHPMailer/PHPMailer</pre></p>';
	exit;
}

if (!file_exists("settings.cfg")) {
	echo '<p>ERROR: Copy <span style="font-family: monospace; padding: 0 5px;">settings.cfg.default</span> to <span style="font-family: monospace; padding: 0 5px;">settings.cfg</span>, add your details to it, and retry.</p>';
	exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

include_once "PHPMailer/src/PHPMailer.php";
include_once "PHPMailer/src/Exception.php";
include_once "PHPMailer/src/SMTP.php";

$settings = parse_ini_file('settings.cfg');

date_default_timezone_set('America/New_York');

$status = file_get_contents('https://www.csuohio.edu/apps/parking/feed.php');
$garages = json_decode($status);

foreach ($garages as $garage) {

	if ($garage->name == $settings['preferred_garage']) {
		$preferred = $garage->SubscriberCapacity - $garage->SubscriberCount;
	} elseif ($garage->name == $settings['backup_garage']) {
		$backup = $garage->SubscriberCapacity - $garage->SubscriberCount;
	}
}

if (($preferred < 5) && ($backup < 5)) {
	$subject = "Park in the street";
	$message = "Lots are full, park elsewhere.";
} elseif (($preferred > $backup) || ($preferred > 50)) {
	$subject = "Park in " . $settings['preferred_garage'];
	$message = $preferred . " spots available.";
} else {
	$subject = "Park in " . $settings['backup_garage'];
	$message = $backup . " spots available";
}

if (ISSET($_GET['email'])) {

	$mail = new PHPMailer;

	$mail->isSMTP();

	// Uncomment for debugging
	//$mail->SMTPDebug = 2;
	$mail->Host = $settings['host'];
	$mail->Port = $settings['port'];
	$mail->SMTPSecure = $settings['security'];
	$mail->SMTPAuth = true;
	$mail->Username = $settings['username'];
	$mail->Password = $settings['password'];
	$mail->setFrom($settings['username'],'Harbormaster');
	$mail->Subject = $subject;
	$mail->addAddress($settings['recipient']);
	$mail->msgHTML($message);
	$mail->AltBody = $message;

	$mail->send();

	// Uncomment for debugging
	// echo "Mailer Error: " . $mail->ErrorInfo;

}

echo '<html>
<head>
<title>Harbormaster | ' . $subject . '</title>
<link type="text/css" rel="stylesheet" media="all" href="style.css">
</head>
<body>

<div id="page-wrapper"';

if (($preferred < 5) && ($backup < 5)) {
	echo ' class="warning"';
}

echo '>
<div id="header">
<div id="site-title">
<h1>' . $subject . '</h1>
</div><!-- #site-title -->
</div><!-- #header -->

<div id="content-wrapper">
<div id="content">';

if (($preferred > $backup) || ($preferred > 50)) {
	echo '<p><strong>' . $preferred . '</strong> spots available in <strong>' . $settings['preferred_garage'] . '</strong></p>
	<p><strong>' . $backup . '</strong> spots available in <strong>' . $settings['backup_garage'] . '</strong></p>';
} else {
	echo '<p>' . $backup . ' spots available in ' . $settings['backup_garage'] . '</p>
	<p>' . $preferred . ' spots available in ' . $settings['preferred_garage'] . '</p>';
}

echo '</div> <!-- #content -->
</div> <!-- #content-wrapper -->

<div id="footer">
<p><strong>' . date('F j, Y \a\t g:i a') . '</strong></p>
<p><a href="https://github.com/brianwarner/harbormaster">Harbormaster on <img src="./github-mark.png" alt="GitHub mark"></a><br>
&copy; <a href="https://bdwarner.com">Brian Warner</a></p>
</div> <!-- #footer -->
</div> <!-- #content -->
</body>
</html';

?>
