<?php
// Start a session to manage user authentication
session_start();

// Include the database connection file.
// This file is assumed to contain a working PDO connection in a variable named $db.
// IMPORTANT: Ensure db_connect.php has PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION for proper error reporting.
require_once 'db_connect.php';

// The authentication check has been removed as per your request.
// Only an admin is expected to access this page.

$message = "";

try {
    // Set PDO error mode to exception for better debugging
    // This should ideally be set in db_connect.php, but included here for emphasis.
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Handle Add, Edit, and Delete Operations ---

    // Handle deletion of a client
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['client_id'])) {
        $client_id = $_POST['client_id'];
        $sql = "DELETE FROM client WHERE client_id = ?"; // Table name updated to 'client'
        $stmt = $db->prepare($sql);
        if ($stmt->execute([$client_id])) {
            $message = "<div class='message-box success'>Client record deleted successfully.</div>";
        } else {
            // This part might not be reached if PDO::ERRMODE_EXCEPTION is set
            $errorInfo = $stmt->errorInfo();
            $message = "<div class='message-box error'>Error deleting client: " . htmlspecialchars($errorInfo[2]) . "</div>";
        }
    }

    // Handle adding a new client
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'add') {
        // Sanitize and validate inputs
        $client_name = trim($_POST['client_name']);
        $company_name = trim($_POST['company_name']);
        $contact_email = trim($_POST['contact_email']);
        $phone_number = trim($_POST['phone_number']);
        $address = trim($_POST['address']);

        if (empty($client_name)) {
            $message = "<div class='message-box error'>Client Name is required.</div>";
        } else {
            $sql = "INSERT INTO client (client_name, company_name, contact_email, phone_number, address) VALUES (?, ?, ?, ?, ?)"; // Table name updated to 'client'
            $stmt = $db->prepare($sql);
            if ($stmt->execute([$client_name, $company_name, $contact_email, $phone_number, $address])) {
                $message = "<div class='message-box success'>New client added successfully.</div>";
            } else {
                // This part might not be reached if PDO::ERRMODE_EXCEPTION is set
                $errorInfo = $stmt->errorInfo();
                $message = "<div class='message-box error'>Error adding client: " . htmlspecialchars($errorInfo[2]) . "</div>";
            }
        }
    }

    // Handle editing an existing client
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'edit') {
        // Sanitize and validate inputs
        $client_id = $_POST['client_id']; // Make sure to get the client_id
        $client_name = trim($_POST['client_name']);
        $company_name = trim($_POST['company_name']);
        $contact_email = trim($_POST['contact_email']);
        $phone_number = trim($_POST['phone_number']);
        $address = trim($_POST['address']);

        if (empty($client_name)) {
            $message = "<div class='message-box error'>Client Name is required.</div>";
        } else {
            $sql = "UPDATE client SET client_name = ?, company_name = ?, contact_email = ?, phone_number = ?, address = ? WHERE client_id = ?"; // Table name updated to 'client'
            $stmt = $db->prepare($sql);
            if ($stmt->execute([$client_name, $company_name, $contact_email, $phone_number, $address, $client_id])) {
                $message = "<div class='message-box success'>Client record updated successfully.</div>";
            } else {
                // This part might not be reached if PDO::ERRMODE_EXCEPTION is set
                $errorInfo = $stmt->errorInfo();
                $message = "<div class='message-box error'>Error updating client: " . htmlspecialchars($errorInfo[2]) . "</div>";
            }
        }
    }

    // --- Fetch all clients for display ---
    $clients = [];
    $sql = "SELECT client_id, client_name, company_name, contact_email, phone_number, address FROM client ORDER BY client_name ASC"; // Table name updated to 'client'
    $stmt = $db->query($sql);

    if ($stmt) {
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Calculate total clients here
        $totalClients = count($clients);
    } else {
        // This part might not be reached if PDO::ERRMODE_EXCEPTION is set
        $message .= "<div class='message-box error'>Error fetching clients. (Statement failed to execute)</div>";
        $totalClients = 0; // Initialize if there's an error
    }

} catch (PDOException $e) {
    // Catching PDO exceptions to display specific database errors
    $message = "<div class='message-box error'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $totalClients = 0; // Initialize if there's a database error
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management</title>
    <link rel="icon" type="image/jpeg" href="pic/logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
		.modal-overlay {
			background-color: rgba(0, 0, 0, 0.5);
			visibility: hidden;
			opacity: 0;
			transition: opacity 0.3s ease, visibility 0.3s ease;
		}
		.modal-overlay.active {
			visibility: visible;
			opacity: 1;
		}
		.message-box-overlay {
            background-color: rgba(0, 0, 0, 0.5);
            transition: opacity 0.3s ease-in-out;
            opacity: 0;
            pointer-events: none;
        }
        .message-box-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }
        /* Styles for message boxes */
        .message-box {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.375rem; /* rounded-md */
            font-weight: 500; /* font-medium */
            text-align: center;
        }
        .message-box.success {
            background-color: #d1fae5; /* bg-green-100 */
            color: #065f46; /* text-green-700 */
            border: 1px solid #34d399; /* border-green-400 */
        }
        .message-box.error {
            background-color: #fee2e2; /* bg-red-100 */
            color: #991b1b; /* text-red-700 */
            border: 1px solid #f87171; /* border-red-400 */
        }
    </style>
</head>
<body>
    <input type="checkbox" id="mobile-sidebar-toggle">
    <div class="dashboard-container">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Main Menu</h2>
                <label for="mobile-sidebar-toggle" class="close-btn"><i class="fas fa-times"></i></label>
            </div>
            <nav>
                <ul>
                    <li><a href="home.php"><i class="fas fa-home icon"></i> Dashboard</a></li>
                    <li><a href="car_info.php"><i class="fas fa-car icon"></i> Car Information</a></li>
                    <li><a href="shipment_track.php"><i class="fas fa-truck icon"></i> Shipment Tracking</a></li>
                    <li><a href="lc.php"><i class="fas fa-folder-open icon"></i> LC Management</a></li>
                    <li><a href="client.php" class="active"><i class="fas fa-user icon"></i> Client Management</a></li>
                    <li><a href="sales.php"><i class="fas fa-chart-bar icon"></i> Sales & Reports</a></li>
                </ul>
            </nav>
        </div>

        <div class="main-content">
            <div class="sidebar-overlay"></div>
            <header class="header">
                <div style="display:flex; align-items:center; gap:1rem;">
                    <label for="mobile-sidebar-toggle" class="menu-btn"><i class="fas fa-bars"></i></label>
                    <div class="logo" style="display: flex; align-items: center; gap: 0.5rem;">
                        <img src="pic/logo.jpg" alt="IMB Logo" style="height: 40px; width: auto;" />
                        <span><span class="logo-highlight">IMB</span> Dashboard</span>
                    </div>
                </div>
                <form action="logout.php" method="post">
                    <button type="submit" class="sign-out-btn flex items-center gap-2">
                        <i class="fas fa-sign-out-alt"></i> Log Out
                    </button>
                </form>
            </header>

            <div class="p-8 max-w-7xl mx-auto">
                <header class="flex flex-col md:flex-row items-center justify-between gap-4 mb-8">
                    <div class="text-center md:text-left">
                        <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 lg:text-5xl">Client Management</h1>
                        <p class="mt-2 text-lg text-gray-500">
                            Add, update, and manage all client details.
                        </p>
                    </div>
                    <button
                        onclick="openAddModal()"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-md shadow-lg transition-all duration-300 transform hover:scale-105 flex items-center gap-2"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add New Client
                    </button>
                </header>

                <?php if (!empty($message)): ?>
                    <?= $message ?>
                <?php endif; ?>

                <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Total Clients</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-indigo-500">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-4xl font-bold"><?= $totalClients ?></div>
                            <p class="text-xs text-gray-400 mt-1">Total clients in the system</p>
                        </div>
                    </div>
                    <div class="bg-gray-50 rounded-xl border border-gray-100 p-6"></div>
                    <div class="bg-gray-50 rounded-xl border border-gray-100 p-6"></div>
                </section>

                <section class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <div class="flex items-center gap-2 mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-gray-400">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input
                            type="text"
                            id="searchQuery"
                            placeholder="Search by client name, company, or contact..."
                            oninput="filterTable()"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 w-full rounded-md shadow-sm border-gray-300 focus:ring-blue-500 focus:border-blue-500"
                        />
                    </div>

                    <div class="w-full overflow-auto">
                        <table id="clientTable" class="w-full caption-bottom text-sm">
                            <thead class="[&_tr]:border-b">
                                <tr class="bg-gray-50">
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Name</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Company</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Contact</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Phone</th>
                                    <th class="h-12 px-4 text-right align-middle font-bold text-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="[&_tr:last-child]:border-0">
                                <?php if (empty($clients)): ?>
                                    <tr>
                                        <td colspan="6" class="p-4 text-center text-gray-500">No clients found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($clients as $client): ?>
                                        <tr class="border-b transition-colors hover:bg-gray-100 duration-200">
                                            <td class="p-4 align-middle"><?= htmlspecialchars($client['client_name']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($client['company_name']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($client['contact_email']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($client['phone_number']) ?></td>
                                            <td class="p-4 align-middle text-right">
												<button type="button" onclick='openEditModal(<?= json_encode([
													'id' => $client['client_id'],
													'name' => $client['client_name'],
													'company' => $client['company_name'],
													'contact' => $client['contact_email'],
													'phone' => $client['phone_number'],
													'address' => $client['address']
												]) ?>)' class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-blue-100 hover:text-blue-600 h-9 w-9 p-0 text-blue-500" title="Edit">
													<i class="fas fa-edit"></i>
												</button>
                                                <form method="POST" class="inline-block" onsubmit="return confirmDelete(event, '<?= $client['client_id'] ?>')">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="client_id" value="<?= $client['client_id'] ?>">
                                                    <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-red-100 hover:text-red-600 h-9 w-9 p-0 text-red-500" title="Delete">
														<i class="fas fa-trash-alt"></i>
													</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <div id="message-box" class="fixed inset-0 z-50 overflow-y-auto hidden items-center justify-center p-4 message-box-overlay">
                    <div class="w-full max-w-sm mx-auto bg-white rounded-xl shadow-2xl p-6 relative">
                        <div class="text-center">
                            <h3 id="message-box-title" class="font-semibold tracking-tight text-xl mb-4"></h3>
                            <p id="message-box-content" class="text-gray-700 mb-6"></p>
                            <button
                                onclick="closeMessageBox()"
                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-md transition-all duration-300 transform hover:scale-105"
                            >
                                OK
                            </button>
                        </div>
                    </div>
                </div>

<div id="client-modal" class="modal-overlay fixed inset-0 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full p-6 mx-4">
        <h2 id="modal-title" class="text-2xl font-bold mb-4 text-gray-900"></h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" id="form-action" value="" />
            <input type="hidden" name="client_id" id="client-id" />
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Client Name</label>
                    <input id="name" name="client_name" required class="mt-1 block w-full rounded-md border-gray-300 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., John Smith" />
                </div>
                <div>
                    <label for="company" class="block text-sm font-medium text-gray-700">Company Name</label>
                    <input id="company" name="company_name" class="mt-1 block w-full rounded-md border-gray-300 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., IMB Motors" />
                </div>
            </div>
            <div>
                <label for="contact" class="block text-sm font-medium text-gray-700">Contact Email</label>
                <input type="email" id="contact" name="contact_email" required class="mt-1 block w-full rounded-md border-gray-300 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., john.smith@example.com" />
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                <input type="tel" id="phone" name="phone_number" class="mt-1 block w-full rounded-md border-gray-300 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., +8801234567890" />
            </div>
            <div>
                <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                <textarea id="address" name="address" rows="3" class="mt-1 block w-full rounded-md border-gray-300 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., House 1, Road 2, Gulshan 1, Dhaka 1212"></textarea>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors">
                    <span id="submit-button-text"></span>
                </button>
            </div>
        </form>
    </div>
</div>
            </div>
        </div>
			 <div class="right-sidebar">
        
              </div>
	</div>
		<footer class="bg-gray-800 text-gray-400 text-center p-4">
                 <p>The SAD Six | Developers</p>
            </footer>
    <script>
        // JavaScript to handle the modal
        const clientModal = document.getElementById('client-modal');
        const modalTitle = document.getElementById('modal-title');
        const form = clientModal.querySelector('form');
        const formActionInput = document.getElementById('form-action');
        const clientIdInput = document.getElementById('client-id');
        const nameInput = document.getElementById('name');
        const companyInput = document.getElementById('company');
        const contactInput = document.getElementById('contact');
        const phoneInput = document.getElementById('phone');
        const addressInput = document.getElementById('address');
        const submitButtonText = document.getElementById('submit-button-text');

		// Open modal for adding a new client
		function openAddModal() {
			modalTitle.textContent = 'Add New Client';
			submitButtonText.textContent = 'Add Client';
			form.reset();
			clientIdInput.value = '';
			formActionInput.value = 'add'; // Set action for adding
			clientModal.classList.add('active'); // Use class for visibility
		}

		// Open modal for editing an existing client
		function openEditModal(client) {
			modalTitle.textContent = 'Edit Client';
			submitButtonText.textContent = 'Update Client';
			formActionInput.value = 'edit'; // Set action for editing

			// Populate form fields
			clientIdInput.value = client.id;
			nameInput.value = client.name;
			companyInput.value = client.company;
			contactInput.value = client.contact;
			phoneInput.value = client.phone;
			addressInput.value = client.address;

			clientModal.classList.add('active'); // Use class for visibility
		}

		// Close the modal
		function closeModal() {
			clientModal.classList.remove('active');
		}

        // Message Box functionality (replaces alert())
        const messageBox = document.getElementById('message-box');
        const messageBoxTitle = document.getElementById('message-box-title');
        const messageBoxContent = document.getElementById('message-box-content');

        function showMessageBox(title, content) {
            messageBoxTitle.textContent = title;
            messageBoxContent.textContent = content;
            messageBox.style.display = 'flex';
            setTimeout(() => messageBox.classList.add('active'), 10);
        }

        function closeMessageBox() {
            messageBox.classList.remove('active');
            setTimeout(() => messageBox.style.display = 'none', 300);
        }

        // Custom delete confirmation
        function confirmDelete(event, clientId) {
            event.preventDefault(); // Prevent default form submission
            showMessageBox(
                "Confirm Deletion",
                "Are you sure you want to delete this client record? This action cannot be undone."
            );

            // Re-bind the OK button to the form submission logic
            document.querySelector('#message-box button').onclick = () => {
                closeMessageBox();
                // Submit the original form
                event.target.submit();
            };
            return false;
        }

        // Search/Filter functionality
        function filterTable() {
            const searchQuery = document.getElementById('searchQuery').value.toLowerCase();
            const table = document.getElementById('clientTable');
            const rows = table.getElementsByTagName('tr');

            for (let i = 1; i < rows.length; i++) { // Start at 1 to skip the header row
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let found = false;
                for (let j = 0; j < cells.length; j++) {
                    const text = cells[j].textContent.toLowerCase();
                    if (text.includes(searchQuery)) {
                        found = true;
                        break;
                    }
                }
                row.style.display = found ? '' : 'none';
            }
        }
    </script>
</body>
</html>