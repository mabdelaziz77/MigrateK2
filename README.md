# Joomla 3.x K2 to Core Content Migration Script

This Joomla 3.x CLI script helps Joomla users who are using the K2 extension to migrate K2 database entities (including items, categories, tags, extra fields, and images) into their corresponding entities of core Joomla content.

## Prerequisites

- Joomla 3.10
- PHP 7.4
- Terminal or Shell access

## Installation

1. Clone or download this repository to the `cli` folder inside your Joomla site's root directory.
2. Fill in the required configuration values in the `migratek2/config.php` file:
   - **Super Admin Username and Password:** Required to create Joomla articles, categories, etc.
   - **Items Per Loop:** Set the number of items to migrate per loop to avoid timeouts.
   - **Extra Fields Mapping:** Map K2 extra fields to corresponding Joomla Articles custom fields (MUST be created before running the script). Below is an example of how to do this:
     ```php
     public $cfMapping = [
         // 'k2_field_id' => 'content_field_id',
         '3' => '1',
         '1' => '2',
         '2' => '3',
     ];
     ```
     In this example, K2 field with ID `3` is mapped to Joomla content field with ID `1`, K2 field `1` to Joomla content field `2`, and so on.
   - **Attachment Field:** Specify the ID of the attachment custom field (Note: Migration of K2 attachments is not yet implemented).

## Usage

1. Navigate to the document root of your Joomla website.
2. Run the script using the command: 
   ```bash
   php cli/migratek2.php
   ```
3. If your Super Admin user has 2FA enabled, you will be prompted to enter the secret key. If not, simply press Enter.
4. The script will display messages indicating the progress of the migration, including the categories and K2 items being migrated.
5. Once the process is complete, you will see the message "Finished migrating K2 database.". See the screenshot below.
   ![image](https://github.com/user-attachments/assets/6cd4462f-343c-4290-81b3-528d1f171a35)


## ☕ Support This Project

If this script has helped you with your migration process, consider supporting its development. Your donations help me maintain and improve this project.

[Buy me a coffee](https://paypal.me/mabdelaziz77) ☕

Thank you for your support!

## ⚠️ Disclaimer & Warranty

This script is provided "as is," without warranty of any kind, express or implied. While I’ve made efforts to ensure it works as intended, it has not been extensively tested. Use it at your own risk.

I am not responsible for any damage or loss of data that may occur as a result of using this script. It is highly recommended to back up your site and database before running the script.
