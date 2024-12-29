# USTP Supply Chain Hub (USCH)

A comprehensive supply chain management system designed for USTP (University of Science and Technology of Southern Philippines) to streamline inventory management, supply requests, procurement processes, and asset tracking.

## 🌟 Key Features

### User Management & Access Control
- Role-based access control (Admin, Supply Personnel, Department Head, Auditor)
- Secure authentication with password hashing
- Department-specific views and permissions
- User activity logging and audit trails

### Inventory Management
- Real-time stock tracking and monitoring
- Low stock alerts and notifications
- Item categorization and status tracking
- Batch import/export functionality
- Expiry date tracking and alerts

### Supply Request Module
- Department-based supply requisitions
- Multi-item request support
- Request status tracking (Pending, Approved, Rejected, Fulfilled)
- Automated inventory updates upon request approval
- Justification and documentation support

### Procurement & Distribution
- Supplier management
- Purchase order generation
- Order tracking and status updates
- Delivery scheduling and monitoring
- Cost tracking and budget management

### Audit & Reporting
- Comprehensive audit trails
- Customizable reports generation
- Stock movement history
- User activity monitoring
- Data analytics and insights

### Additional Features
- Return and exchange management
- Alert and notification system
- Data backup and recovery
- Mobile-responsive interface
- Dark theme support

## 🛠️ Technical Requirements

### Server Requirements
- PHP 8.0 or higher
- MySQL 8.0 or higher
- Apache 2.4 or higher
- mod_rewrite enabled

### Recommended Software
- XAMPP (for local development)
- Web Browser: Chrome/Firefox (latest versions)
- Text Editor: VSCode with PHP extensions

### PHP Extensions
- PDO PHP Extension
- MySQL PHP Extension
- GD PHP Extension
- OpenSSL PHP Extension
- Mbstring PHP Extension

## 📦 Installation

### 1. Database Setup
```sql
-- Create database
CREATE DATABASE IF NOT EXISTS usch_db;
```

### 2. Application Setup
1. Clone the repository:
   ```bash
   git clone https://github.com/kntjspr/supply-chain-hub.git
   cd usch
   ```

2. Configure database connection:
   - Rename `includes/config.sample.php` to `includes/config.php`
   - Update database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'usch_db');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

3. Set up the application:
   - Visit: `http://localhost/usch/install.php`
   - Follow the installation wizard:
     1. Database Configuration
     2. Admin Account Setup
     3. Initial Data Setup

4. Verify installation:
   - Login with admin credentials
   - Check all modules are accessible
   - Verify database tables are created

### 3. Directory Permissions
```bash
chmod 755 -R /path/to/usch
chmod 777 -R /path/to/usch/assets/uploads
chmod 777 -R /path/to/usch/logs
```

## 🔒 Security Features

### Authentication & Authorization
- Secure password hashing using bcrypt
- CSRF token protection
- Session management and timeout
- Role-based access control
- IP-based login attempt tracking

### Data Protection
- Prepared statements for SQL queries
- Input validation and sanitization
- XSS protection
- Output escaping
- Secure file upload handling

### Audit & Logging
- User action logging
- Login attempt tracking
- Critical operation logging
- Error logging
- System change tracking

## 📁 Project Structure
```
usch/
├── assets/
│   ├── css/          # Stylesheets
│   ├── js/           # JavaScript files
│   ├── images/       # Image assets
│   └── uploads/      # User uploads
├── includes/
│   ├── config.php    # Configuration
│   ├── functions.php # Helper functions
│   ├── auth.php      # Authentication
│   └── db.php        # Database connection
├── database/
│   ├── schema.sql    # Main schema
│   ├── updates.sql   # Database updates
│   └── notifications.sql # Notification schema
├── templates/        # CSV templates
├── logs/            # System logs
└── cursor_docs/     # Documentation
```

## 🔄 Database Schema
- See [ER Diagram](cursor_docs/er_diagram.md) for complete database structure
- Key tables:
  - users
  - departments
  - inventory
  - supply_requests
  - procurement_orders
  - audit_logs

## 👥 User Roles & Permissions

### Admin
- Full system access
- User management
- System configuration
- Report generation
- Audit log access

### Supply Personnel
- Inventory management
- Request processing
- Stock updates
- Basic reporting

### Department Head
- Department requests
- Request approval
- Department reports
- Stock viewing

### Auditor
- Audit log viewing
- Report generation
- Transaction history
- No modification rights

## 🛟 Troubleshooting

### Common Issues
1. Database Connection Errors
   - Verify database credentials
   - Check MySQL service status
   - Confirm database exists

2. Permission Issues
   - Check file/folder permissions
   - Verify user role settings
   - Check .htaccess configuration

3. Upload Problems
   - Verify directory permissions
   - Check PHP upload settings
   - Confirm file size limits

### Error Logging
- Check `/logs/error.log` for system errors
- Review `/logs/audit.log` for user actions
- Monitor `/logs/login.log` for authentication issues

## 🔄 Reset & Recovery

### Database Reset
```php
// Visit reset.php to:
1. Drop existing database
2. Delete config file
3. Restart installation
```

### Backup & Restore
- Regular automated backups
- Manual backup option
- Point-in-time recovery
- Data export functionality

## 📝 Development Guidelines

### Coding Standards
- Follow PHP PSR-12 standards
- Use meaningful variable names
- Comment complex logic
- Document all functions

### Version Control
- Use descriptive commit messages
- Create feature branches
- Test before merging
- Tag releases properly

### Testing
- Test all user roles
- Verify form validations
- Check error handling
- Test edge cases

## 📄 License & Credits

### License
This project is proprietary software developed for USTP.
All rights reserved © 2024

### Credits
- Bootstrap 5.3.0
- jQuery 3.6.0
- DataTables 1.11.5
- Font Awesome 6.0.0
