<?php
/**
 * AI Service - Gemini AI Analysis System
 * Sử dụng Google Gemini 2.5 Flash cho phân tích hình ảnh sản phẩm
 */

class AIService {
    private $geminiApiKey;
    private $geminiUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent';
    
    public function __construct() {
        $this->loadEnvironmentVariables();
        $this->geminiApiKey = getenv('GEMINI_API_KEY');
    }
    
    private function loadEnvironmentVariables() {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!getenv($key)) {
                        putenv("$key=$value");
                    }
                }
            }
        }
    }
    
    /**
     * Kiểm tra health của AI service
     */
    public function healthCheck() {
        return [
            'gemini' => [
                'status' => !empty($this->geminiApiKey) ? 'configured' : 'not_configured',
                'api_key' => !empty($this->geminiApiKey),
                'service' => 'Gemini 2.5 Flash'
            ]
        ];
    }
    
    /**
     * Analyze shoes với Gemini Pro Vision
     */
    public function analyzeWithGemini($imagePath, $prompt = null) {
        if (empty($this->geminiApiKey)) {
            return ['error' => 'Gemini API key not configured'];
        }
        
        try {
            // Mã hóa image to base64
            $imageData = $this->encodeImage($imagePath);
            
            // Default prompt for shoe analysis
            if (!$prompt) {
                $prompt = $this->getShoeAnalysisPrompt();
            }
            
            $url = $this->geminiUrl . '?key=' . $this->geminiApiKey;
            
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => 'image/jpeg',
                                    'data' => $imageData
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.0,
                    'topK' => 1,
                    'topP' => 1,
                    'maxOutputTokens' => 4096
                ]
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("Gemini API Error: HTTP $httpCode - $response");
                return ['error' => "API returned HTTP $httpCode", 'details' => $response];
            }
            
            $result = json_decode($response, true);
            
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $content = $result['candidates'][0]['content']['parts'][0]['text'];
                return $this->parseAIResponse($content, 'gemini');
            }
            
            return ['error' => 'Invalid response format', 'raw' => $result];
            
        } catch (Exception $e) {
            error_log("Gemini Exception: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Main analysis method - sử dụng Gemini AI
     * Alias cho analyzeWithGemini để tương thích với code cũ
     */
    public function analyze($imagePath, $prompt = null) {
        return $this->analyzeWithGemini($imagePath, $prompt);
    }
    
    /**
     * Parse AI response (JSON hoặc text)
     */
    private function parseAIResponse($content, $source) {
        // Thử parse JSON trước
        $jsonMatch = [];
        if (preg_match('/\{[^}]+\}/', $content, $jsonMatch)) {
            $parsed = json_decode($jsonMatch[0], true);
            if ($parsed) {
                $parsed['success'] = true;
                $parsed['ai_source'] = $source;
                $parsed['raw_response'] = $content;
                return $parsed;
            }
        }
        
        // Fallback: parse text response
        $result = [
            'success' => true,
            'ai_source' => $source,
            'raw_response' => $content
        ];
        
        // Trích xuất brand
        if (preg_match('/brand[:\s]+([A-Za-z\s]+)/i', $content, $match)) {
            $result['brand'] = trim($match[1]);
        }
        
        // Trích xuất model
        if (preg_match('/model[:\s]+([A-Za-z0-9\s\-]+)/i', $content, $match)) {
            $result['model'] = trim($match[1]);
        }
        
        // Trích xuất color
        if (preg_match('/color[:\s]+([A-Za-zÀ-ỹ\s]+)/i', $content, $match)) {
            $result['color'] = trim($match[1]);
        }
        
        // Trích xuất size
        if (preg_match('/size[:\s]+(\d+)/i', $content, $match)) {
            $result['size'] = trim($match[1]);
        }
        
        // Trích xuất confidence
        if (preg_match('/confidence[:\s]+([\d\.]+)/i', $content, $match)) {
            $result['confidence'] = floatval($match[1]);
        } else {
            $result['confidence'] = 0.5; // Default medium confidence
        }
        
        return $result;
    }
    
    /**
     * Encode image to base64
     */
    private function encodeImage($imagePath) {
        $imageData = file_get_contents($imagePath);
        return base64_encode($imageData);
    }
    
    /**
     * Get default shoe analysis prompt
     */
    private function getShoeAnalysisPrompt() {
        return "Analyze this shoe image in detail. You are an expert in Vietnamese shoe retail.

Please identify and return ONLY a JSON object with these fields:

{
  \"brand\": \"Brand name (Nike, Adidas, Puma, Converse, Vans, New Balance, etc.)\",
  \"model\": \"Specific model name (Air Max 270, UltraBoost 22, etc.)\",
  \"color\": \"Primary color in Vietnamese (Đen, Trắng, Xanh Navy, Đỏ, etc.)\",
  \"size\": \"Size number if visible (35-47)\",
  \"confidence\": \"Your confidence score (0.0-1.0)\",
  \"category\": \"Type (Running, Lifestyle, Basketball, Football, etc.)\",
  \"material\": \"Main material (Leather, Canvas, Mesh, Synthetic, etc.)\",
  \"estimated_price_vnd\": \"Estimated retail price in Vietnam market\"
}

Focus on accuracy for Vietnamese market. If you cannot determine a field, leave it as null or empty string.";
    }
}
