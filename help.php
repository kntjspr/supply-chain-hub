<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: login.php');
    exit();
}

try {
    $pdo = get_db_connection();
    
    // Get user info
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Help topics
$topics = [
    'getting_started' => [
        'title' => 'Getting Started',
        'icon' => 'fas fa-rocket',
        'sections' => [
            [
                'title' => 'System Overview',
                'content' => 'The USTP Supply Chain Hub (USCH) is a comprehensive system designed to manage inventory, supply requests, procurement, and distribution processes. It provides features for tracking items, managing requests, and generating reports.'
            ],
            [
                'title' => 'First Steps',
                'content' => 'After logging in, you\'ll see the dashboard which provides an overview of key metrics and recent activities. The sidebar menu contains all available modules, and you can access your profile settings through the user menu.'
            ],
            [
                'title' => 'Navigation',
                'content' => 'Use the sidebar menu to navigate between different modules. Each module has its own set of features and functionalities designed for specific tasks.'
            ]
        ]
    ],
    'inventory' => [
        'title' => 'Inventory Management',
        'icon' => 'fas fa-boxes',
        'sections' => [
            [
                'title' => 'Adding Items',
                'content' => 'To add new items to the inventory, click the "Add Item" button in the Inventory Management module. Fill in the required details including item name, description, quantity, and stock levels.'
            ],
            [
                'title' => 'Managing Stock',
                'content' => 'Monitor stock levels through the inventory list. Items below minimum stock level will be highlighted. You can update quantities manually or through procurement orders.'
            ],
            [
                'title' => 'Import/Export',
                'content' => 'Use the import feature to add multiple items using a CSV file. You can also export the current inventory to CSV for reporting or backup purposes.'
            ]
        ]
    ],
    'requests' => [
        'title' => 'Supply Requests',
        'icon' => 'fas fa-file-alt',
        'sections' => [
            [
                'title' => 'Creating Requests',
                'content' => 'Click "New Request" to start a supply request. Select items from the inventory and specify the required quantities. Add justification or notes as needed.'
            ],
            [
                'title' => 'Request Status',
                'content' => 'Track your requests through the status indicators: Pending, Approved, or Rejected. Administrators can review and process requests.'
            ],
            [
                'title' => 'Request History',
                'content' => 'View your request history to track past requests and their outcomes. Use filters to find specific requests by date or status.'
            ]
        ]
    ],
    'procurement' => [
        'title' => 'Procurement & Distribution',
        'icon' => 'fas fa-shopping-cart',
        'sections' => [
            [
                'title' => 'Creating Orders',
                'content' => 'Create procurement orders by selecting a supplier and adding items with quantities and unit prices. The system will calculate the total order amount.'
            ],
            [
                'title' => 'Order Processing',
                'content' => 'Track orders through their lifecycle: Pending → Ordered → Received. When orders are received, inventory quantities are automatically updated.'
            ],
            [
                'title' => 'Supplier Management',
                'content' => 'Maintain a list of suppliers with their contact information and terms. This information is used when creating procurement orders.'
            ]
        ]
    ],
    'returns' => [
        'title' => 'Return and Exchange',
        'icon' => 'fas fa-undo',
        'sections' => [
            [
                'title' => 'Return Requests',
                'content' => 'Submit return requests for items that need to be returned or exchanged. Select the original supply request and specify items with their condition.'
            ],
            [
                'title' => 'Processing Returns',
                'content' => 'Administrators review return requests and can approve or reject them. Approved returns update the inventory quantities accordingly.'
            ],
            [
                'title' => 'Return History',
                'content' => 'Track all return requests and their status. View details of processed returns including processor notes and timestamps.'
            ]
        ]
    ],
    'reports' => [
        'title' => 'Audit & Reporting',
        'icon' => 'fas fa-history',
        'sections' => [
            [
                'title' => 'Audit Log',
                'content' => 'View the system audit log to track all significant actions including user activities, inventory changes, and request processing.'
            ],
            [
                'title' => 'Inventory Reports',
                'content' => 'Generate reports on inventory movement, stock levels, and usage patterns. Export reports to CSV for further analysis.'
            ],
            [
                'title' => 'Department Usage',
                'content' => 'Analyze supply usage by department, including request patterns, approval rates, and most requested items.'
            ]
        ]
    ],
    'settings' => [
        'title' => 'Settings & Preferences',
        'icon' => 'fas fa-cog',
        'sections' => [
            [
                'title' => 'Profile Settings',
                'content' => 'Update your personal information including name, email, and department. Ensure your contact details are kept up to date.'
            ],
            [
                'title' => 'Password Security',
                'content' => 'Change your password regularly for security. Use strong passwords with a mix of letters, numbers, and special characters.'
            ],
            [
                'title' => 'Notification Settings',
                'content' => 'Configure your notification preferences for various events such as low stock alerts, request updates, and system notifications.'
            ]
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Center - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .card-body { color: white; }
        body {
            background-color: #1a1f3c;
            color: #ffffff;
        }
        .sidebar {
            background-color: #2c3154;
            min-height: 100vh;
            padding: 1rem;
        }
        .nav-link {
            color: #ffffff;
            opacity: 0.8;
            transition: all 0.3s;
        }
        .nav-link:hover {
            opacity: 1;
            color: #ffffff;
        }
        .nav-link.active {
            background-color: rgba(255, 255, 255, 0.1);
            opacity: 1;
        }
        .main-content {
            padding: 2rem;
        }
        .card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            backdrop-filter: blur(10px);
            margin-bottom: 1.5rem;
        }
        .help-topic {
            cursor: pointer;
            transition: all 0.3s;
        }
        .help-topic:hover {
            transform: translateY(-5px);
        }
        .help-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        .topic-content {
            display: none;
        }
        .topic-content.active {
            display: block;
        }
        .section-title {
            color: #ffffff;
            margin-bottom: 0.5rem;
        }
        .section-content {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 1.5rem;
        }
        #search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: rgba(44, 49, 84, 0.95);
            border-radius: 0 0 15px 15px;
            z-index: 1000;
            display: none;
        }
        .search-result-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            cursor: pointer;
            transition: all 0.3s;
        }
        .search-result-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .search-result-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar">
                <div class="text-center mb-4">
                    <img src="assets/images/logo.png" alt="USTP Logo" style="width: 80px;">
                    <h4 class="mt-2">USCH</h4>
                    <p class="mb-0">USTP Supply Chain Hub</p>
                </div>
                
                <div class="user-info mb-4">
                    <div class="d-flex align-items-center">
                        <div class="avatar me-3">
                            <i class="fas fa-user-circle fa-2x"></i>
                        </div>
                        <div>
                            <h6 class="mb-0"><?php echo sanitize_output($user['full_name']); ?></h6>
                            <small class="text"><?php echo sanitize_output($user['role']); ?></small>
                        </div>
                    </div>
                </div>

                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="inventory.php">
                            <i class="fas fa-boxes me-2"></i> Inventory Management
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="requests.php">
                            <i class="fas fa-file-alt me-2"></i> Supply Request Module
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="procurement.php">
                            <i class="fas fa-shopping-cart me-2"></i> Procurement & Distribution
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="audit.php">
                            <i class="fas fa-history me-2"></i> Audit & Reporting
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="returns.php">
                            <i class="fas fa-undo me-2"></i> Return and Exchange
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="alerts.php">
                            <i class="fas fa-bell me-2"></i> Alert & Notifications
                        </a>
                    </li>
                    <?php if (is_admin()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users me-2"></i> User Management
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <ul class="nav flex-column mt-4">
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog me-2"></i> Settings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="help.php">
                            <i class="fas fa-question-circle me-2"></i> Help Center
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4>Help Center</h4>
                    <div class="position-relative" style="width: 300px;">
                        <input type="text" class="form-control" id="search-help" 
                               placeholder="Search help topics..." 
                               style="background: rgba(255, 255, 255, 0.1); color: #ffffff; border-color: rgba(255, 255, 255, 0.2);">
                        <div id="search-results"></div>
                    </div>
                </div>

                <!-- Help Topics Grid -->
                <div class="row mb-4">
                    <?php foreach ($topics as $key => $topic): ?>
                    <div class="col-md-4 col-lg-3 mb-4">
                        <div class="card help-topic" data-topic="<?php echo $key; ?>">
                            <div class="card-body text-center">
                                <div class="help-icon">
                                    <i class="<?php echo $topic['icon']; ?>"></i>
                                </div>
                                <h5 class="card-title"><?php echo $topic['title']; ?></h5>
                                <small class="text">Click to view details</small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Topic Content -->
                <?php foreach ($topics as $key => $topic): ?>
                <div class="topic-content" id="<?php echo $key; ?>-content">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="mb-4"><?php echo $topic['title']; ?></h4>
                            <?php foreach ($topic['sections'] as $section): ?>
                            <h5 class="section-title"><?php echo $section['title']; ?></h5>
                            <p class="section-content"><?php echo $section['content']; ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Show topic content when clicking on a topic card
            $('.help-topic').click(function() {
                const topicId = $(this).data('topic');
                $('.topic-content').removeClass('active');
                $(`#${topicId}-content`).addClass('active');
                
                // Scroll to content
                $('html, body').animate({
                    scrollTop: $(`#${topicId}-content`).offset().top - 20
                }, 500);
            });
            
            // Search functionality
            $('#search-help').on('input', function() {
                const query = $(this).val().toLowerCase();
                const results = [];
                
                if (query.length < 2) {
                    $('#search-results').hide();
                    return;
                }
                
                // Search through topics and sections
                <?php echo json_encode($topics); ?>.forEach((topic, key) => {
                    if (topic.title.toLowerCase().includes(query)) {
                        results.push({
                            title: topic.title,
                            topic: key,
                            type: 'topic'
                        });
                    }
                    
                    topic.sections.forEach(section => {
                        if (section.title.toLowerCase().includes(query) || 
                            section.content.toLowerCase().includes(query)) {
                            results.push({
                                title: `${topic.title} - ${section.title}`,
                                topic: key,
                                type: 'section'
                            });
                        }
                    });
                });
                
                // Display results
                if (results.length > 0) {
                    const html = results.map(result => `
                        <div class="search-result-item" data-topic="${result.topic}">
                            <div class="fw-bold">${result.title}</div>
                            <small class="text">${result.type === 'topic' ? 'Topic' : 'Section'}</small>
                        </div>
                    `).join('');
                    
                    $('#search-results').html(html).show();
                } else {
                    $('#search-results').html('<div class="p-3">No results found</div>').show();
                }
            });
            
            // Handle search result click
            $(document).on('click', '.search-result-item', function() {
                const topicId = $(this).data('topic');
                $('.help-topic[data-topic="' + topicId + '"]').click();
                $('#search-results').hide();
                $('#search-help').val('');
            });
            
            // Hide search results when clicking outside
            $(document).click(function(e) {
                if (!$(e.target).closest('.position-relative').length) {
                    $('#search-results').hide();
                }
            });
        });
    </script>
</body>
</html> 