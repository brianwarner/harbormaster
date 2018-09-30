# Harbormaster

Harbormaster is a program that collects parking information for Cleveland  
State University and sends an email recommending the best parking garage,  
based upon available spaces.

Huge thanks to PHPMailer for their code and examples!

To use Harbormaster:

1. Get PHPMailer:

   git clone https://github.com/PHPMailer/PHPMailer

2. Edit settings.cfg

  a) Choose your primary and secondary garages:

    * 'West Garage'
    * 'Central Garage'
    * 'South Garage'
    * 'Prospect Garage'
    * 'East Garage'

  b) Decide which (if any) garage is weathersafe.

  c) Add your email and password (it's configured for Gmail by default)

3. Set a cron job to wget or curl http://yourharbormasterURL?email

4. Alternately, bookmark http://yourharbormasterURL/ to view status  
		without sending an email to yourself.

Harbormaster is licensed under Apache 2.0

If you have questions or issues, contact Brian Warner <brian@bdwarner.com>

