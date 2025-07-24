<?php
// Set the correct header to output JSON
header('Content-Type: application/json');

// Define the path to your data file
$dataFile = 'data.json';
$membersFile = 'members.json';

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
    $members = json_decode(file_get_contents($membersFile), true);
    $data['members'] = $members;
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
    $members = json_decode(file_get_contents($membersFile), true);
    $data['members'] = $members;
    $saveRequired = false;
    $membersSaveRequired = false;

    switch ($action) {
        case 'addMember':
            $data['members'][] = ['name' => $payload['name'], 'email' => $payload['email']];
            $membersSaveRequired = true;
            break;

        case 'removeMember':
            $nameToRemove = $payload['name'];
            $data['members'] = array_values(array_filter($data['members'], function($member) use ($nameToRemove) {
                return $member['name'] !== $nameToRemove;
            }));
            $membersSaveRequired = true;
            break;

        case 'editMember':
            $originalName = $payload['originalName'];
            foreach ($data['members'] as &$member) {
                if ($member['name'] === $originalName) {
                    $member['name'] = $payload['name'];
                    $member['email'] = $payload['email'];
                    break;
                }
            }
            $membersSaveRequired = true;
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

        case 'addExtraPayment':
            $totalAmountDue = 0;
            foreach ($data['extraPayments'] as $p) {
                if ($p['name'] === $payload['extraPaymentFor']) {
                    $totalAmountDue = $p['amount'];
                    break;
                }
            }

            $existingEntryIndex = -1;
            foreach ($data['extraPaymentDetails'] as $i => $p) {
                if ($p['memberName'] === $payload['memberName'] && $p['extraPaymentFor'] === $payload['extraPaymentFor']) {
                    $existingEntryIndex = $i;
                    break;
                }
            }

            if ($existingEntryIndex !== -1) {
                $data['extraPaymentDetails'][$existingEntryIndex]['paidAmount'] += $payload['amountPaid'];
            } else {
                $data['extraPaymentDetails'][] = [
                    'memberName' => $payload['memberName'],
                    'extraPaymentFor' => $payload['extraPaymentFor'],
                    'paidAmount' => $payload['amountPaid'],
                    'totalAmountDue' => $totalAmountDue,
                    'date' => date('c')
                ];
                $existingEntryIndex = count($data['extraPaymentDetails']) - 1;
            }

            $entry = &$data['extraPaymentDetails'][$existingEntryIndex];
            $remaining = $entry['totalAmountDue'] - $entry['paidAmount'];
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

        case 'editExpense':
            $index = $payload['index'];
            $data['expenses'][$index] = $payload['updatedExpense'];
            $saveRequired = true;
            break;

        case 'deleteExpense':
            $index = $payload['index'];
            array_splice($data['expenses'], $index, 1);
            $saveRequired = true;
            break;

        case 'addEvent':
            $data['extraPayments'][] = $payload;
            $saveRequired = true;
            break;

        case 'editEvent':
            $index = $payload['index'];
            $data['extraPayments'][$index] = $payload['event'];
            $saveRequired = true;
            break;

        case 'deleteEvent':
            $index = $payload['index'];
            array_splice($data['extraPayments'], $index, 1);
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

    if ($membersSaveRequired) {
        if (saveData($membersFile, $data['members'])) {
            unset($data['members']);
            if ($saveRequired) {
                if (saveData($dataFile, $data)) {
                    echo json_encode(['status' => 'success', 'message' => 'Data updated successfully.']);
                } else {
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => 'Failed to write to data file.']);
                }
            } else {
                echo json_encode(['status' => 'success', 'message' => 'Data updated successfully.']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to write to members file.']);
        }
    }
    else if ($saveRequired) {
        unset($data['members']);
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
