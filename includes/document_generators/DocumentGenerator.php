<?php
/**
 * Main Document Generator Entry Point
 */
require_once 'DocumentGeneratorFactory.php';

class DocumentGenerator {
    private $factory;
    
    public function __construct($conn) {
        $this->factory = new DocumentGeneratorFactory($conn);

        
    }
    
    public function generateDocument($request_id) {
        return $this->factory->generateDocument($request_id);
    }
}

// Usage example:
/*
// Include database connection
require_once '../config/database.php';
$conn = getConnection();

// Create generator instance
$generator = new DocumentGenerator($conn);

// Generate document for request ID
$result = $generator->generateDocument(123);

if ($result['success']) {
    echo "Document generated successfully!\n";
    echo "Filename: " . $result['filename'] . "\n";
    echo "Path: " . $result['filepath'] . "\n";
    
    // You can now serve the file to user
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
    readfile($result['filepath']);
} else {
    echo "Error: " . $result['message'] . "\n";
}
*/
?>