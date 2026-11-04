AGRI-MARKET SYSTEM

Project Description

The Agri-Market System is a web-based agricultural marketplace designed to connect farmers and buyers through an online platform. It provides a centralized system for managing agricultural products, auctions, bidding activities, transactions, and delivery records.

The system allows administrators to manage user accounts, farmer records, product listings, auctions, transactions, delivery statuses, and commission rates. Buyers, also referred to as bidders, can register, log in, browse available auctions, place bids, monitor bidding activities, and view their transaction records.

The project aims to provide a more organized and accessible platform for agricultural trading through digital product management, auction-based purchasing, and transaction monitoring.

Features and Functionalities

Admin Panel
- Dashboard overview and system statistics.
- User account management.
- Farmer and buyer management.
- Agricultural product management.
- Bidding and auction management.
- Transaction management.
- Delivery status monitoring.
- Commission rate management.
- Sales reports and system summaries.

Bidder Panel
- Buyer registration and login.
- Bidder dashboard.
- Viewing available auctions.
- Placing bids on agricultural products.
- Monitoring active and winning bids.
- Viewing bidding history.
- Checking auction results.
- Viewing transaction and payment records.
- Monitoring delivery information.

Technology Stack

The Agri-Market System was developed using the following technologies:

- PHP – Used for server-side programming, application processing, and database operations.
- MySQL – Used for storing and managing system information, including user accounts, products, auctions, bids, and transactions.
- HTML5 – Used to structure the web pages and application content.
- CSS3 – Used for designing the interface, layouts, colors, and overall appearance of the system.
- JavaScript – Used for client-side interactions and interface functionality.
- Font Awesome 6.5.0 – Used for icons and visual elements.
- Apache – Used as the web server for running the application.
- XAMPP – Used as the local development environment.
- Git and GitHub – Used for source code management, version control, and project collaboration.

Database Information

The system uses MySQL as its database management system.

The database name used in the local development environment is:

`agri_markettt`

The local database connection uses the following configuration:

- Host: `localhost`
- Username: `root`
- Password: Blank (default XAMPP configuration)
- Port: `3306`
- Database: `agri_markettt`

The database stores information related to users, farmers, products, auctions, bids, transactions, deliveries, commission rates, and activity logs.

System Requirements

Before installing and running the application, make sure that the following are available:

- Windows operating system or another supported operating system.
- XAMPP with Apache and MySQL.
- PHP with MySQLi extension enabled.
- MySQL or MariaDB database server.
- Modern web browser such as Google Chrome, Microsoft Edge, or Mozilla Firefox.
- Git (optional, for version control).

Installation and Deployment Steps

A. Local Deployment Using XAMPP

Step 1: Install XAMPP

Download and install XAMPP from the official website:

https://www.apachefriends.org/

After installation, open the XAMPP Control Panel and start the following services:

- Apache
- MySQL

Step 2: Copy the Project Folder

Copy the Agri-Market project folder into the XAMPP `htdocs` directory.

Example directory:

`C:\xampp\htdocs\agri`

Step 3: Create the Database

1. Open your web browser.
2. Navigate to `http://localhost/phpmyadmin`.
3. Click the Databases tab.
4. Create a new database named `agri_markettt`.

Step 4: Import the Database

1. Select the `agri_markettt` database.
2. Click the Import tab.
3. Choose the SQL database backup file of the Agri-Market System.
4. Click Import or Go.
5. Wait until the database import process is completed.

Note: The SQL database backup must be available separately from the project source code.

Step 5: Configure the Database Connection

Ensure that the database connection settings in the PHP files match your local MySQL configuration.

The default local settings are:

- Host: `localhost`
- Username: `root`
- Password: blank
- Database: `agri_markettt`
- Port: `3306`

Step 6: Run the Application

Open your web browser and enter the following address:

`http://localhost/agri/`

The Agri-Market login page should appear.

Step 7: Log In to the System

Use an existing administrator or registered buyer account to access the appropriate system interface.

The application includes an initial administrator account creation routine in `index.php`. Change the default administrator credentials before deploying the application publicly.

B. Production Deployment Using Web Hosting

Step 1: Prepare the Hosting Environment

Choose a web hosting provider that supports:

- PHP.
- MySQL or MariaDB.
- MySQLi extension.
- HTTPS/SSL certificates.

Step 2: Create a Production Database

1. Log in to the hosting control panel.
2. Navigate to the MySQL Database Manager.
3. Create a new database.
4. Create a database user.
5. Assign the required privileges to the database user.
6. Take note of the database host, database name, username, and password.

Step 3: Import the Database

1. Open phpMyAdmin through the hosting control panel.
2. Select the newly created database.
3. Import the Agri-Market SQL backup file.
4. Verify that the required database tables have been imported successfully.

Step 4: Upload the Project Files

1. Open the hosting File Manager or connect through FTP.
2. Navigate to the website's document root, commonly named `public_html`.
3. Upload all Agri-Market project files.
4. Make sure the folder structure and required image assets, including `bg.png` and `logo.png`, are preserved.

Step 5: Update Database Connection Settings

Update the MySQLi database connection settings in the PHP files using the credentials provided by the hosting provider.

Replace the local database configuration with the production database credentials.

Do not use the default XAMPP root account or an empty database password on a public server.

Step 6: Test the Deployed Application

Open the website using its assigned domain.

Check the following functionalities:

- Login and registration.
- Administrator dashboard.
- User management.
- Product management.
- Bidding and auction management.
- Transaction management.
- Delivery monitoring.
- Bidder dashboard.
- Bidding history and transaction records.

Ensure that all pages load correctly and that the application connects successfully to the production database.

Step 7: Apply Security Measures

- Change default administrator credentials.
- Use a strong database password.
- Enable HTTPS.
- Disable public PHP error display.
- Protect database credentials.
- Avoid uploading sensitive files and private database records to a public repository.

Version Control and GitHub

Git and GitHub are used to manage the source code and track project development.

The development process includes:

- Creating and managing project branches.
- Tracking source code modifications.
- Staging updated files.
- Committing changes with descriptive messages.
- Pushing updates to the GitHub repository.

These practices help maintain organized project development and preserve previous versions of the application.

Project Scope

The Agri-Market System focuses on providing an organized online platform for agricultural marketplace activities through administrator and bidder interfaces.

The system integrates user management, agricultural product management, auction and bidding activities, transaction processing, delivery monitoring, and reporting functionalities.

The project also aims to improve the overall user experience through interface enhancements while maintaining the existing backend processes and database operations.

---

Project Name: Agri-Market System

Project Type: Web-Based Agricultural Marketplace

Development Environment: XAMPP

Database: MySQL (`agri_markettt`)

Primary Programming Technologies: PHP, MySQL, HTML5, CSS3, and JavaScript