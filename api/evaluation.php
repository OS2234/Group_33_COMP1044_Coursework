<?php

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Gets internship_id from student_id and assessor_id
 */
function getInternshipId($pdo, $studentId, $assessorId) {
    $stmt = $pdo->prepare("
        SELECT internship_id FROM internship 
        WHERE student_id = ? AND assessor_id = ?
    ");
    $stmt->execute([$studentId, $assessorId]);
    $result = $stmt->fetch();
    
    if (!$result) {
        throw new Exception('No internship found for this student-assessor pair');
    }
    
    return $result['internship_id'];
}

/**
 * Gets evaluation by internship_id
 */
function getEvaluationByInternshipId($pdo, $internshipId) {
    $stmt = $pdo->prepare("
        SELECT e.*, i.student_id, i.assessor_id, s.name as student_name, u.username as assessor_name
        FROM evaluation e
        JOIN internship i ON e.internship_id = i.internship_id
        JOIN student s ON i.student_id = s.student_id
        JOIN assessor a ON i.assessor_id = a.assessor_id
        JOIN user u ON a.user_id = u.user_id
        WHERE e.internship_id = ?
    ");
    $stmt->execute([$internshipId]);
    return $stmt->fetch();
}

/**
 * Validates internship end date
 */
function validateInternshipPeriod($pdo, $internshipId) {
    $stmt = $pdo->prepare("
        SELECT i.end_date, i.status
        FROM internship i
        WHERE i.internship_id = ?
    ");
    $stmt->execute([$internshipId]);
    $internship = $stmt->fetch();
    
    if ($internship && $internship['end_date']) {
        $endDate = new DateTime($internship['end_date']);
        $today = new DateTime();
        if ($endDate < $today) {
            return ['valid' => false, 'error' => 'Cannot evaluate: Internship has already ended'];
        }
    }
    
    return ['valid' => true];
}

/**
 * Creates or updates an evaluation using internship_id
 */
function saveEvaluation($pdo, $data) {
    $pdo->beginTransaction();
    
    try {
        // Get internship_id if only student_id and assessor_id were provided
        if (isset($data['student_id']) && isset($data['assessor_id']) && !isset($data['internship_id'])) {
            $data['internship_id'] = getInternshipId($pdo, $data['student_id'], $data['assessor_id']);
        }
        
        if (!isset($data['internship_id'])) {
            throw new Exception('internship_id is required');
        }
        
        // Validate internship period
        $validation = validateInternshipPeriod($pdo, $data['internship_id']);
        if (!$validation['valid']) {
            throw new Exception($validation['error']);
        }
        
        // Check if evaluation already exists
        $checkStmt = $pdo->prepare("
            SELECT evaluation_id FROM evaluation 
            WHERE internship_id = ?
        ");
        $checkStmt->execute([$data['internship_id']]);
        $existing = $checkStmt->fetch();
        
        $scoresJson = json_encode($data['scores']);
        
        if ($existing) {
            // Update existing evaluation
            $stmt = $pdo->prepare("
                UPDATE evaluation 
                SET scores = ?, weighted_total = ?, remarks = ?, evaluated_at = NOW()
                WHERE internship_id = ?
            ");
            $stmt->execute([
                $scoresJson,
                $data['weightedTotal'],
                $data['remarks'],
                $data['internship_id']
            ]);
            $evaluationId = $existing['evaluation_id'];
        } else {
            // Insert new evaluation
            $stmt = $pdo->prepare("
                INSERT INTO evaluation (internship_id, scores, weighted_total, remarks, evaluated_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $data['internship_id'],
                $scoresJson,
                $data['weightedTotal'],
                $data['remarks']
            ]);
            $evaluationId = $pdo->lastInsertId();
        }
        
        // Update internship status to 'Evaluated'
        $updateStmt = $pdo->prepare("
            UPDATE internship 
            SET status = 'Evaluated' 
            WHERE internship_id = ?
        ");
        $updateStmt->execute([$data['internship_id']]);
        
        $pdo->commit();
        return ['success' => true, 'evaluation_id' => $evaluationId];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ============================================
// REQUEST HANDLER
// ============================================

switch($method) {
    case 'GET':
        $studentId = $_GET['student_id'] ?? null;
        $assessorId = $_GET['assessor_id'] ?? null;
        $internshipId = $_GET['internship_id'] ?? null;
        
        try {
            if ($internshipId) {
                // Direct lookup by internship_id
                $result = getEvaluationByInternshipId($pdo, $internshipId);
                echo json_encode($result);
            } else if ($studentId && $assessorId) {
                // Lookup by student_id and assessor_id (backward compatible)
                $internshipId = getInternshipId($pdo, $studentId, $assessorId);
                $result = getEvaluationByInternshipId($pdo, $internshipId);
                echo json_encode($result);
            } else {
                echo json_encode(null);
            }
        } catch (Exception $e) {
            error_log('Evaluation GET error: ' . $e->getMessage());
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        try {
            $result = saveEvaluation($pdo, $data);
            echo json_encode($result);
        } catch (Exception $e) {
            error_log('Evaluation POST error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        break;
}
?>