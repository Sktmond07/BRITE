<?php
class OCRClient {
    private $python_path;
    private $script_path;
    private $debug;
    
    public function __construct($debug = false) {
        $this->python_path = __DIR__ . '/python/python.exe';
        if (!file_exists($this->python_path)) {
            $this->python_path = 'python';
        }
        $this->script_path = __DIR__ . '/ocr_script.py';
        $this->debug = $debug;
    }
    
    public function isPythonAvailable() {
        $cmd = '"' . $this->python_path . '" --version 2>&1';
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }
    
    public function processImage($imagePath, $side = 'front') {
        if (!file_exists($imagePath)) {
            return ['success' => false, 'error' => 'Image not found'];
        }
        
        if (!file_exists($this->script_path)) {
            return ['success' => false, 'error' => 'OCR script not found'];
        }
        
        $cmd = '"' . $this->python_path . '" "' . $this->script_path . '" "' . $imagePath . '" "' . $side . '" 2>&1';
        
        if ($this->debug) {
            error_log("Running: " . $cmd);
        }
        
        exec($cmd, $output, $returnCode);
        $outputText = implode("\n", $output);
        
        if ($returnCode !== 0) {
            return ['success' => false, 'error' => 'Python failed: ' . substr($outputText, 0, 500)];
        }
        
        $jsonStart = strpos($outputText, '{');
        if ($jsonStart === false) {
            return ['success' => false, 'error' => 'No JSON output: ' . substr($outputText, 0, 200)];
        }
        
        $result = json_decode(substr($outputText, $jsonStart), true);
        if ($result === null) {
            return ['success' => false, 'error' => 'Invalid JSON: ' . substr($outputText, 0, 200)];
        }
        
        return $result;
    }
}
?>