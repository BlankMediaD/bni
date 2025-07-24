<?php
// Set the correct header to output JSON
header('Content-Type: application/json');

// Define the path to your data file
$dataFile = 'data.json';

// --- Function to get the current data from the file ---
function getData($filePath) {
    if (!file_exists($filePath)) {
        // If the file doesn't exist, create it with initial data.
        $initialData = getInitialData();
        file_put_contents($filePath, json_encode($initialData, JSON_PRETTY_PRINT));
        return $initialData;
    }
    $json = file_get_contents($filePath);
    return json_decode($json, true);
}

// --- Function to save data to the file ---
function saveData($filePath, $data) {
    // Use JSON_PRETTY_PRINT to keep the file human-readable.
    return file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
}

// --- Initial Data Structure ---
function getInitialData() {
    $months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    $monthlyPayments = [];
    foreach ($months as $m) {
        $monthlyPayments[] = ['month' => $m, 'amount' => 1500];
    }

    return [
        'members' => [
            ['name' => 'Amit Kumar', 'email' => 'amit.k@example.com'],
            ['name' => 'Priya Sharma', 'email' => 'priya.s@example.com'],
            ['name' => 'Rahul Verma', 'email' => 'rahul.v@example.com']
        ],
        'extraPayments' => [['name' => 'Diwali Celebration', 'amount' => 500], ['name' => 'New Year Party', 'amount' => 750]],
        'monthlyPayments' => $monthlyPayments,
        'monthlyPaymentDetails' => [],
        'extraPaymentDetails' => [],
        'expenses' => []
    ];
}

// --- Main Logic ---
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // --- READ DATA ---
    $data = getData($dataFile);
    echo json_encode($data);

} elseif ($method === 'POST') {
    // --- WRITE/UPDATE DATA ---
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON received.']);
        exit;
    }

    $action = $input['action'] ?? null;
    $payload = $input['payload'] ?? null;
    $data = getData($dataFile);
    $saveRequired = false;

    switch ($action) {
        case 'addMember':
            $data['members'][] = ['name' => $payload['name'], 'email' => $payload['email']];
            $saveRequired = true;
            break;

        case 'removeMember':
            $nameToRemove = $payload['name'];
            $data['members'] = array_values(array_filter($data['members'], function($member) use ($nameToRemove) {
                return $member['name'] !== $nameToRemove;
            }));
            $saveRequired = true;
            break;
        
        case 'addMonthlyPayment':
            $totalAmountDue = 0;
            foreach ($data['monthlyPayments'] as $p) {
                if ($p['month'] === $payload['month']) {
                    $totalAmountDue = $p['amount'];
                    break;
                }
            }

            $existingEntryIndex = -1;
            foreach ($data['monthlyPaymentDetails'] as $i => $p) {
                if ($p['memberName'] === $payload['memberName'] && $p['month'] === $payload['month']) {
                    $existingEntryIndex = $i;
                    break;
                }
            }

            if ($existingEntryIndex !== -1) {
                $data['monthlyPaymentDetails'][$existingEntryIndex]['amountPaid'] += $payload['amountPaid'];
            } else {
                $data['monthlyPaymentDetails'][] = [
                    'memberName' => $payload['memberName'],
                    'month' => $payload['month'],
                    'amountPaid' => $payload['amountPaid'],
                    'totalAmountDue' => $totalAmountDue,
                    'paidVia' => $payload['paidVia'],
                    'date' => date('c')
                ];
                $existingEntryIndex = count($data['monthlyPaymentDetails']) - 1;
            }
            
            $entry = &$data['monthlyPaymentDetails'][$existingEntryIndex];
            $remaining = $entry['totalAmountDue'] - $entry['amountPaid'];
            $entry['remainingAmount'] = max(0, $remaining);
            $entry['status'] = $remaining <= 0 ? ($remaining < 0 ? "Overpaid: Excess " . abs($remaining) : "Fully Paid") : "Partially Paid: Remaining " . $remaining;
            $saveRequired = true;
            break;
        
        // Add cases for other actions like addExpense, addEvent, updateAllFees etc.
        // For brevity, a generic 'save' can handle these for now if the frontend sends the whole object
        case 'addExpense':
            $data['expenses'][] = $payload;
            $saveRequired = true;
            break;
        
        case 'addEvent':
            $data['extraPayments'][] = $payload;
            $saveRequired = true;
            break;
        
        case 'updateAllFees':
            $data['monthlyPayments'] = $payload;
            $saveRequired = true;
            break;

        default:
            // Fallback to overwrite all data if no specific action matches
            // This can be used for complex updates managed by the client
            if ($payload) {
                $data = $payload;
                $saveRequired = true;
            }
            break;
    }

    if ($saveRequired) {
        if (saveData($dataFile, $data)) {
            echo json_encode(['status' => 'success', 'message' => 'Data updated successfully.']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to write to data file.']);
        }
    } else {
        echo json_encode(['status' => 'no-action', 'message' => 'No action performed.']);
    }

} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
}
?>
