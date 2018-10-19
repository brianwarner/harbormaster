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

if (file_exists("google_analytics.inc")) {
	include_once "google_analytics.inc";
}

$settings = parse_ini_file('settings.cfg',FALSE,INI_SCANNER_TYPED);
$cache_file = 'cached.html';
$cache_seconds = $settings['cache seconds'];

// Use the cached info, if current and available

if (file_exists($cache_file) && time() - $cache_seconds < filemtime($cache_file)) {
	$html = file_get_contents($cache_file);
	$subject = substr($html,strpos($html,'<h1>') + 4,strpos($html,'</h1>') - strpos($html,'<h1>') - 4);
} else {

	date_default_timezone_set('America/New_York');

	$status = file_get_contents('https://www.csuohio.edu/apps/parking/feed.php');
	$garages = json_decode($status);

	foreach ($garages as $garage) {

		if ($garage->name == $settings['preferred_garage']) {
			$preferred = max(0,$garage->SubscriberCapacity - $garage->SubscriberCount);
		} elseif ($garage->name == $settings['backup_garage']) {
			$backup = max(0,$garage->SubscriberCapacity - $garage->SubscriberCount);
		}
	}

	// Get the weather, if an API key was set

	$openweathermap_api_key = $settings['openweathermap_api_key'];

	$rain = False;
	$rain_total = 0;
	$snow = False;
	$snow_total = 0;
	$heat = False;
	$cold = False;
	$high = -100;
	$low = 200;
	$entry = 2;
	$precipitation_end = 0;

	if ($openweathermap_api_key) {

		$weather_url = "http://api.openweathermap.org/data/2.5/forecast?id=5150529&lang=en&units=imperial&cnt=3&APPID=" . $openweathermap_api_key;

		$weather = json_decode(file_get_contents($weather_url));

		foreach ($weather->list as $weather_detail) {
			if (property_exists($weather_detail,'rain')) {
				if (array_key_exists('3h',$weather_detail->rain)) {
					$rain_total += $weather_detail->rain->{'3h'};
					$precipitation_end = 3 * $entry;
				}
			}

			if (property_exists($weather_detail,'snow')) {
				if (array_key_exists('3h',$weather_detail->snow)) {
					$snow_total += $weather_detail->snow->{'3h'};
					$precipitation_end = 3 * $entry;
				}
			}

			if ($weather_detail->main->temp_max > $high) {
				$high = $weather_detail->main->temp_max;
			}

			if ($weather_detail->main->temp_min < $low) {
				$low = $weather_detail->main->temp_min;
			}

			$entry += 1;
		}

		if ($rain_total > $settings['rain_threshold']) {
			$rain = True;
		}

		if ($snow_total > $settings['snow_threshold']) {
			$snow = True;
		}

		if ($high > 85) {
			$temperature = 'it will be <strong>hot</strong>';
		} elseif ($low < 45) {
			$temperature = 'it will be <strong>chilly</strong>';
		} elseif ($low < 35) {
			$temperature = 'it will be <strong>cold</strong>';
		} elseif ($low < 15) {
			$temperature = 'it will be <strong>very cold</strong>';
		} else {
			$temperature = '';
		}

		if ($rain && !$snow) {
			$precipitation = 'it may <strong>rain</strong> in the next <strong>' . $precipitation_end . '</strong> hours';
		} elseif (!$rain && $snow) {
			$precipitation = 'it may <strong>snow</strong> in the next <strong>' . $precipitation_end . '</strong> hours';
		} elseif ($rain && $snow) {
			$precipitation = 'it may <strong>rain and snow</strong> in the next <strong>' . $precipitation_end . '</strong> hours';
		} else {
			$precipitation = '';
		}

		if ($temperature && $precipitation) {
			$forecast = ucfirst($temperature) . ' and ' . $precipitation;
		} elseif ($temperature && !$precipitation) {
			$forecast = ucfirst($temperature);
		} elseif (!$termperature && $precipitation) {
			$forecast = ucfirst($precipitation);
		} else {
			$forecast = '';
		}
	}

	if (($preferred < 5) && ($backup < 5)) {

		$subject = "Park in the street";
		$message = "Lots are full, park elsewhere.\n" . $forecast;
		$selected_garage = 'none';

	} elseif (($preferred > $backup) || ($preferred > 50)) {

		$subject = "Park in " . $settings['preferred_garage'];
		$message = $preferred . " spots available.\n" . $forecast;
		$selected_garage = 'preferred';

		// Determine if weather overrides decision

		if ($rain || $snow || $heat || $cold) {
			if (!$settings['preferred_garage_weathersafe']) {
				if (($settings['backup_garage_weathersafe']) && ($backup > 10)) {

					$subject = "Park in " . $settings['backup_garage'];
					$message = $backup . " spots available.\n" . $forecast;
					$selected_garage = 'backup';

				} else {
					$message .= "\nBe prepared for the weather.";
				}
			}
		}

	} else {

		$subject = "Park in " . $settings['backup_garage'];
		$message = $backup . " spots available.\n" . $forecast;
		$selected_garage = 'backup';

		// Determine if weather overrides decision

		if ($rain || $snow || $heat || $cold) {
			if (!$settings['backup_garage_weathersafe']) {
				if (($settings['preferred_garage_weathersafe']) && ($preferred > 10)) {

					$subject = "Park in " . $settings['preferred_garage'];
					$message = $backup . " spots available.\n" . $forecast;
					$selected_garage = 'preferred';

				} else {
					$message .= "\nBe prepared for the weather.";
				}
			}
		}
	}

	$html = '<html>
	<head>
	<title>Harbormaster</title>

	<style type="text/css">

		#body {
			width: 100%;
		}

		#page-wrapper {
			margin: 0 auto;
			width: 1280px;
			border: 1px solid grey;
			padding: 10px 5px;
			color: #333;
			font-size: 280%;
			text-align: center;
		}

		#page-wrapper.warning {
			background-color: red;
			color: white;
		}

		a:link, a:visited {
			color: #111;
			font-weight: bold;
			text-decoration: none;
		}

		.warning a:link, .warning a:visited {
			color: white;
		}

		#header {
			padding: 20px 0;
			width: 100%;
		}

		#site-title {
			font-weight: bold;
		}

		#content-wrapper {
			border-top: 1px solid grey;
			border-bottom: 1px solid grey;
			min-height: 200px;
			margin: 20px 0;
		}

		.warning #content-wrapper {
			border-color: white;

		}

		#content {

			padding: 10px;
		}

		#footer {
			font-size: 80%;
			margin-top: 10px;
			text-align: center;
		}

	</style>

	<script type="text/javascript">
		var date_generated = new Date("' . date('Y/m/d H:i:s') . '");
		onload = function () {
			onfocus = function () {
				if (Date.now() - date_generated > 10*60*1000) {
					location.reload (true)
				}
			}
		}
	</script>

	</head>
	<body>

	<div id="page-wrapper"';

	if ($selected_garage == 'none') {
		$html .= ' class="warning"';
	}

	$html .= '>
	<div id="header">
	<div id="site-title">
	<h1>' . $subject . '</h1>
	</div><!-- #site-title -->
	</div><!-- #header -->

	<div id="content-wrapper">
	<div id="content">';

	if ($forecast) {
		$html .= '<p>' . $forecast . '</p>';
	}

	if ($selected_garage == 'preferred') {

		$html .= '<p><strong>' . $preferred . '</strong> spot';

		if ($preferred != 1) {
			$html .= 's';
		}

		$html .= ' available in <strong>' . $settings['preferred_garage'] . '</strong></p>
		<p><strong>' . $backup . '</strong> spot';

		if ($backup != 1) {
			$html .= 's';
		}

		$html .= ' available in <strong>' . $settings['backup_garage'] . '</strong></p>';
	} else {
		$html .= '<p><strong>' . $backup . '</strong> spot';

		if ($backup != 1) {
			$html .= 's';
		}

		$html .= ' available in <strong>' . $settings['backup_garage'] . '</strong></p>
		<p><strong>' . $preferred . '</strong> spot';

		if ($preferred != 1) {
			$html .= 's';
		}

		$html .= ' available in <strong>' . $settings['preferred_garage'] . '</strong></p>';
	}

	$html .= '</div> <!-- #content -->
	</div> <!-- #content-wrapper -->

	<div id="footer">
	<p><strong>' . date('F j, Y \a\t g:i a') . '</strong></p>
	<p><a href="https://github.com/brianwarner/harbormaster">Harbormaster on GitHub</a><br>
	<a href="https://bdwarner.com">&copy; Brian Warner</a></p>
	</div> <!-- #footer -->
	</div> <!-- #content -->
	</body>
</html>
';

	// Write the output to cache

	$cache = fopen($cache_file, 'w');
	fwrite($cache, $html);
	fclose($cache);
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
	$mail->msgHTML($html);
	$mail->AltBody = $message;

	$mail->send();

	// Uncomment for debugging
	// echo "Mailer Error: " . $mail->ErrorInfo;

} else {

	echo $html;

}

?>
