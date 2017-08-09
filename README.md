# Zencart - Pin Payments Integration

## Installing 

**Before you proceed, make a backup of your database. Installation is done at your own risk.**

Clone this repository to your computer or download one of the tarballs from the releases section. 

Using ftp or a similar method, upload all the files from the *files* directory to your store root.

One required core file has been modified (ajax.php). You can just replace the file in your installation of Zen Cart or merge the changes if necessary.

One core file was modified (admin/orders.php) to allow issuing refunds directly from the admin section. Uploading this file is optional since refunds can also be issued from the Pin Payments merchant dashboard. Make sure you use the file that matches your Zen Cart version. In case you have made any changes to this file, make sure you merge it carefully (you can use a tool such as DiffMerge). All changes have been clearly commented. Otherwise, you can simply overwrite. 

After all the files are in place, log in to your admin section and navigate to admin->Modules->Payment. Locate *Pin Payments* and select it, then click on the "install" button on the right hand side. Enter you transaction key (obtained from the Pin Payments dashboard). Change any other settings as required and click "update".

In case installation fails to add the required database tables, you will have to install the tables manually. Log in to your admin section and go to Tools->Install SQL Patches. Open the "mysql_updates/pin_payments.sql that comes with this plugin, copy the content, paste it in the admin section and click the "send" button. NOTE: do NOT do this if you're not getting an error message instructing you to do that.

Your integration is now installed and ready for use.

**Keep in mind this module requires a secure connection (SSL certificate) to work.**

## Usage

Accept credit and debit cards, face‑to‑face or across the world. Simple signup with same-day activation. Visit https://pin.net.au/ for more information.

This plugin has a built-in support for Tokens - the card tokens API allows you to securely store credit card details in exchange for a card token. This card token can then be used to create a single charge, or to create multiple charges over time.

## License 

GPL. Please see the LICENSE.txt file within the repository for further details.
