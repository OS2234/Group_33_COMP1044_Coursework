<?php

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');


function calculateAutoStatus($startDate, $endDate, $hasEvaluation) {
    if ($hasEvaluation) {
        return 'Evaluated';
    }
    
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    if (!empty($endDate)) {
        $end = new DateTime($endDate);
        $end->setTime(0, 0, 0);
        if ($today > $end) {
            return 'Pending';
        }
    }
    
    return 'Ongoing';
}


function updateAllStudentStatuses($pdo) {
    // Get internships that have evaluations
    $evalStmt = $pdo->query("
        SELECT DISTINCT i.internship_id 
        FROM internship i
        INNER JOIN evaluation e ON i.internship_id = e.internship_id
    ");
    $evaluatedSet = array_flip($evalStmt->fetchAll(PDO::FETCH_COLUMN));
    
    // Get all internships
    $stmt = $pdo->query("
        SELECT i.internship_id, i.student_id, i.start_date, i.end_date, s.name as student_name
        FROM internship i
        JOIN student s ON i.student_id = s.student_id
    ");
    $internships = $stmt->fetchAll();
    $updatedStudents = [];
    
    foreach ($internships as $internship) {
        $hasEvaluation = isset($evaluatedSet[$internship['internship_id']]);
        $autoStatus = calculateAutoStatus(
            $internship['start_date'],
            $internship['end_date'],
            $hasEvaluation
        );
        
        $updateStmt = $pdo->prepare("UPDATE internship SET status = ? WHERE internship_id = ?");
        $updateStmt->execute([$autoStatus, $internship['internship_id']]);
        
        $updatedStudents[] = [
            'internship_id' => $internship['internship_id'],
            'student_id' => $internship['student_id'],
            'student_name' => $internship['student_name'],
            'new_status' => $autoStatus
        ];
    }
    
    return $updatedStudents;
}

// ============================================
// REQUEST HANDLER
// ============================================

try {
    $updatedStudents = updateAllStudentStatuses($pdo);
    
    echo json_encode([
        'success' => true,
        'message' => 'Status update completed',
        'updated_count' => count($updatedStudents),
        'updated_students' => $updatedStudents
    ]);
} catch (Exception $e) {
    error_log('Update statuses error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>