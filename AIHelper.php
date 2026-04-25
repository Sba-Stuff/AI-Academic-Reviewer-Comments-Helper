<?php
// Error logging - Add at the VERY TOP
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);

// Increase execution time limits
set_time_limit(12000); // 2 minutes
ini_set('max_execution_time', 12000);

function customErrorHandler($errno, $errstr, $errfile, $errline) {
    $logEntry = date('Y-m-d H:i:s') . " - Error: [$errno] $errstr in $errfile on line $errline\n";
    file_put_contents(__DIR__ . '/php_errors.log', $logEntry, FILE_APPEND);
    return true;
}
set_error_handler('customErrorHandler');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $logEntry = date('Y-m-d H:i:s') . " - Fatal Error: " . print_r($error, true) . "\n";
        file_put_contents(__DIR__ . '/php_errors.log', $logEntry, FILE_APPEND);
    }
});

// Log all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logEntry = date('Y-m-d H:i:s') . " - POST Request: Action=" . ($_POST['action'] ?? 'unknown') . "\n";
    file_put_contents(__DIR__ . '/php_errors.log', $logEntry, FILE_APPEND);
}

// Configuration
define('DATA_DIR', __DIR__ . '/data');
define('AUTHOR_FILE', DATA_DIR . '/author_changes.txt');
define('REVIEWER_FILE', DATA_DIR . '/reviewer_comments.txt');
define('LM_STUDIO_URL', 'http://localhost:1234/v1/completions'); // LM Studio API endpoint

// Create data directory if it doesn't exist
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

// Function to log LM Studio responses
function logLmStudioResponse($response, $error = null, $duration = null) {
    $logEntry = date('Y-m-d H:i:s') . " - LM Studio ";
    if ($duration) {
        $logEntry .= "(Duration: {$duration}s) ";
    }
    $logEntry .= "\n";
    if ($error) {
        $logEntry .= "Error: " . $error . "\n";
    }
    $logEntry .= "Raw Response: " . substr($response, 0, 2000) . "\n---\n";
    file_put_contents(__DIR__ . '/lmstudio_debug.log', $logEntry, FILE_APPEND);
}

// Function to query LM Studio API
function queryLMStudio($prompt) {
    $data = [
        'prompt' => $prompt,
        'max_tokens' => 500, // Reduced for faster response
        'temperature' => 0.7,
        'top_p' => 0.95,
        'frequency_penalty' => 0.5,
        'presence_penalty' => 0.5,
        'stop' => ["\n\nHuman:", "\n\nUser:"]
    ];
    
    $startTime = microtime(true);
    
    $ch = curl_init(LM_STUDIO_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 900); // 90 second timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1000);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $duration = round(microtime(true) - $startTime, 2);
    curl_close($ch);
    
    // Log the response
    logLmStudioResponse($response, $curlError ?: null, $duration);
    
    if ($curlError) {
        return "Error connecting to LM Studio after {$duration}s: " . $curlError . "\n\nPlease ensure LM Studio is running with the API server enabled on port 1234.";
    }
    
    if ($httpCode !== 200) {
        return "LM Studio returned error code: " . $httpCode . " (Duration: {$duration}s)";
    }
    
    // Parse JSON response
    $result = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return "LM Studio returned invalid JSON: " . json_last_error_msg();
    }
    
    if (isset($result['choices'][0]['text'])) {
        return trim($result['choices'][0]['text']);
    } elseif (isset($result['error'])) {
        return "LM Studio Error: " . $result['error']['message'];
    } else {
        return "Unexpected response from LM Studio. Please check your configuration.";
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure clean output
    ob_clean();
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_author':
            $content = $_POST['content'] ?? '';
            $timestamp = date('Y-m-d H:i:s');
            
            // Format entry with timestamp
            $entry = "[" . $timestamp . "]\n" . $content;
            
            // Check if file exists to decide append vs new
            if (file_exists(AUTHOR_FILE) && filesize(AUTHOR_FILE) > 0) {
                // Append with separator
                file_put_contents(AUTHOR_FILE, "\n---\n" . $entry, FILE_APPEND);
                $message = 'Changes appended with timestamp';
            } else {
                // New file
                file_put_contents(AUTHOR_FILE, $entry);
                $message = 'New changes saved with timestamp';
            }
            echo json_encode(['success' => true, 'message' => $message]);
            break;
            
        case 'clear_author':
            // Clear all author changes
            file_put_contents(AUTHOR_FILE, '');
            echo json_encode(['success' => true, 'message' => 'All author changes cleared']);
            break;
            
        case 'save_reviewer':
            $content = $_POST['content'] ?? '';
            // Reviewer comments are saved without appending (overwrite)
            file_put_contents(REVIEWER_FILE, $content);
            echo json_encode(['success' => true, 'message' => 'Reviewer comments saved successfully']);
            break;
            
        case 'get_author_entries':
            $entries = [];
            if (file_exists(AUTHOR_FILE)) {
                $content = file_get_contents(AUTHOR_FILE);
                $content = trim($content);
                if (!empty($content)) {
                    // Parse entries separated by "---"
                    $rawEntries = explode("\n---\n", $content);
                    foreach ($rawEntries as $entry) {
                        $entry = trim($entry);
                        if (preg_match('/\[(.*?)\]\n(.*)/s', $entry, $matches)) {
                            $entries[] = [
                                'timestamp' => $matches[1],
                                'content' => trim($matches[2])
                            ];
                        } elseif (!empty($entry)) {
                            // Handle entries without timestamp format (legacy)
                            $entries[] = [
                                'timestamp' => 'Unknown date',
                                'content' => $entry
                            ];
                        }
                    }
                }
                // Return entries in reverse chronological order (most recent first)
                $entries = array_reverse($entries);
            }
            echo json_encode(['success' => true, 'entries' => $entries]);
            break;
            
        case 'load_reviewer':
            $content = file_exists(REVIEWER_FILE) ? file_get_contents(REVIEWER_FILE) : '';
            echo json_encode(['success' => true, 'content' => $content]);
            break;
            
        case 'ai_query':
            $prompt = $_POST['prompt'] ?? '';
            $context = $_POST['context'] ?? '';
            
            // Build the full prompt with context
            $fullPrompt = "You are an AI Academic Helper. Keep responses concise (max 300 words).\n\n";
            if (!empty($context)) {
                $fullPrompt .= "Reviewer Comments:\n" . substr($context, 0, 500) . "\n\n";
            }
            $fullPrompt .= "Question: " . $prompt . "\n\n";
            $fullPrompt .= "Give specific, actionable advice in 2-3 short paragraphs.";
            
            $response = queryLMStudio($fullPrompt);
            echo json_encode(['success' => true, 'response' => $response]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Academic Helper | Reviewer Response Assistant</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
            color: #eee;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            margin-bottom: 10px;
            font-size: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .subtitle {
            text-align: center;
            margin-bottom: 30px;
            color: #888;
            font-size: 0.9rem;
        }

        .dashboard {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .panel {
            background: rgba(30, 30, 46, 0.95);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .panel-header {
            background: rgba(0, 0, 0, 0.3);
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .panel-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .panel-header.author h2 { color: #4CAF50; }
        .panel-header.reviewer h2 { color: #FF9800; }
        .panel-header.ai h2 { color: #9C27B0; }

        .button-group {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s ease;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .btn-success {
            background: linear-gradient(135deg, #4CAF50, #45a049);
        }

        .btn-warning {
            background: linear-gradient(135deg, #FF9800, #fb8c00);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f44336, #d32f2f);
        }

        .btn-secondary {
            background: rgba(100, 100, 140, 0.6);
        }

        .panel-content {
            padding: 20px;
            flex: 1;
            min-height: 400px;
            display: flex;
            flex-direction: column;
        }

        textarea {
            width: 100%;
            background: rgba(20, 20, 35, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            color: #eee;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            resize: vertical;
            line-height: 1.5;
            margin-bottom: 15px;
        }

        #authorContent {
            min-height: 180px;
        }

        #reviewerContent {
            min-height: 400px;
        }

        textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .entries-section {
            flex: 1;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 15px;
            margin-top: 5px;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .entries-section h3 {
            font-size: 0.9rem;
            margin-bottom: 10px;
            color: #aaa;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .entries-list {
            flex: 1;
            overflow-y: auto;
            max-height: 280px;
        }

        .entry-item {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            border-left: 3px solid #4CAF50;
        }

        .entry-timestamp {
            color: #4CAF50;
            font-size: 0.7rem;
            margin-bottom: 8px;
            font-family: monospace;
        }

        .entry-content {
            color: #ddd;
            line-height: 1.4;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.85rem;
        }

        .ai-query-section {
            display: flex;
            flex-direction: column;
            gap: 12px;
            height: 100%;
        }

        .query-input {
            display: flex;
            gap: 10px;
        }

        .query-input input {
            flex: 1;
            padding: 12px 15px;
            background: rgba(20, 20, 35, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: #eee;
            font-size: 0.9rem;
        }

        .query-input input:focus {
            outline: none;
            border-color: #667eea;
        }

        .ai-response {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            padding: 15px;
            min-height: 280px;
            flex: 1;
            overflow-y: auto;
            font-size: 0.9rem;
            line-height: 1.5;
            white-space: pre-wrap;
        }

        .ai-response.loading {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
        }

        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #667eea;
            animation: spin 0.6s linear infinite;
            margin-right: 10px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .status-bar {
            text-align: center;
            padding: 10px;
            font-size: 0.8rem;
            color: #888;
        }

        .status-bar.success {
            color: #4CAF50;
        }

        .status-bar.error {
            color: #f44336;
        }

        .icon {
            width: 18px;
            height: 18px;
            vertical-align: middle;
            display: inline-block;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(100, 100, 140, 0.5);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(100, 100, 140, 0.8);
        }

        @media (max-width: 1000px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
            
            body {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>AI Academic Helper</h1>
        <div class="subtitle">Intelligent Assistant for Addressing Reviewer Comments | Powered by LM Studio</div>

        <div class="dashboard">
            <div class="panel">
                <div class="panel-header author">
                    <h2>
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                        </svg>
                        Author Changes
                    </h2>
                    <div class="button-group">
                        <button class="btn btn-success" id="saveAuthorBtn">Save (Append)</button>
                        <button class="btn btn-danger" id="clearAuthorBtn">Clear</button>
                    </div>
                </div>
                <div class="panel-content">
                    <textarea id="authorContent" placeholder="Write your response or revision notes here..."></textarea>
                    
                    <div class="entries-section">
                        <h3>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                            </svg>
                            Recent History
                        </h3>
                        <div class="entries-list" id="authorEntriesList">
                            <div style="color: #666; text-align: center; padding: 10px;">Loading...</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header reviewer">
                    <h2>
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        Reviewer Comments
                    </h2>
                    <div class="button-group">
                        <button class="btn btn-warning" id="saveReviewerBtn">Save</button>
                        <button class="btn btn-secondary" id="loadReviewerBtn">Load</button>
                    </div>
                </div>
                <div class="panel-content">
                    <textarea id="reviewerContent" placeholder="Paste reviewer comments here..."></textarea>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header ai">
                    <h2>
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2a10 10 0 0 1 10 10c0 5.5-4.5 10-10 10-2.5 0-4.5-1-6-2.5L2 20l2.5-3.5C2.5 15 2 13 2 10a10 10 0 0 1 10-10z"/>
                        </svg>
                        AI Assistant
                    </h2>
                    <div class="button-group">
                        <button class="btn btn-secondary" id="clearAiBtn">Clear</button>
                    </div>
                </div>
                <div class="panel-content">
                    <div class="ai-query-section">
                        <div class="query-input">
                            <input type="text" id="aiPrompt" placeholder="Ask AI for help with reviewer comments...">
                            <button class="btn btn-primary" id="askAiBtn">Ask AI</button>
                        </div>
                        <div class="ai-response" id="aiResponse">
                            <span style="color: #888;">Hello! I am your AI Academic Helper.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="statusBar" class="status-bar"></div>
    </div>

    <script>
        const authorTextarea = document.getElementById('authorContent');
        const reviewerTextarea = document.getElementById('reviewerContent');
        const aiPromptInput = document.getElementById('aiPrompt');
        const aiResponseDiv = document.getElementById('aiResponse');
        const statusBar = document.getElementById('statusBar');
        const authorEntriesList = document.getElementById('authorEntriesList');

        function showStatus(message, isError = false) {
            statusBar.textContent = message;
            statusBar.className = `status-bar ${isError ? 'error' : 'success'}`;
            setTimeout(() => {
                if (statusBar.textContent === message) {
                    statusBar.textContent = '';
                    statusBar.className = 'status-bar';
                }
            }, 3000);
        }

        async function apiCall(action, data = {}) {
            const formData = new URLSearchParams();
            formData.append('action', action);
            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON Parse Error:', text);
                    return { success: false, message: 'Server returned invalid response' };
                }
            } catch (error) {
                showStatus('Network error: ' + error.message, true);
                return { success: false, message: error.message };
            }
        }

        async function loadAuthorEntries() {
            const result = await apiCall('get_author_entries');
            if (result.success && result.entries) {
                if (result.entries.length === 0) {
                    authorEntriesList.innerHTML = '<div style="color: #666; text-align: center; padding: 10px;">No history yet</div>';
                } else {
                    authorEntriesList.innerHTML = '';
                    result.entries.forEach(entry => {
                        const entryDiv = document.createElement('div');
                        entryDiv.className = 'entry-item';
                        entryDiv.innerHTML = `<div class="entry-timestamp">${escapeHtml(entry.timestamp)}</div><div class="entry-content">${escapeHtml(entry.content)}</div>`;
                        authorEntriesList.appendChild(entryDiv);
                    });
                }
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        async function saveAuthor() {
            const content = authorTextarea.value;
            if (!content.trim()) {
                showStatus('Please enter some content', true);
                return;
            }
            const result = await apiCall('save_author', { content });
            if (result.success) {
                showStatus(result.message);
                authorTextarea.value = '';
                loadAuthorEntries();
            } else {
                showStatus('Error: ' + result.message, true);
            }
        }

        async function clearAuthor() {
            if (confirm('Clear ALL history?')) {
                const result = await apiCall('clear_author');
                if (result.success) {
                    showStatus('All cleared');
                    authorTextarea.value = '';
                    loadAuthorEntries();
                }
            }
        }

        async function saveReviewer() {
            const result = await apiCall('save_reviewer', { content: reviewerTextarea.value });
            showStatus(result.success ? 'Saved!' : 'Error: ' + result.message, !result.success);
        }

        async function loadReviewer() {
            const result = await apiCall('load_reviewer');
            if (result.success) {
                reviewerTextarea.value = result.content;
                showStatus(result.content ? 'Loaded' : 'No saved comments');
            }
        }

        async function askAI() {
            const prompt = aiPromptInput.value.trim();
            if (!prompt) {
                showStatus('Enter a question', true);
                return;
            }

            aiResponseDiv.innerHTML = '<div class="loading"><div class="spinner"></div> Consulting AI...</div>';
            aiResponseDiv.classList.add('loading');

            const result = await apiCall('ai_query', { 
                prompt: prompt, 
                context: reviewerTextarea.value 
            });
            
            aiResponseDiv.classList.remove('loading');
            
            if (result.success) {
                aiResponseDiv.innerHTML = result.response.replace(/\n/g, '<br>');
                showStatus('Response received!');
            } else {
                aiResponseDiv.innerHTML = `<span style="color: #f44336;">Error: ${result.message}</span>`;
                showStatus('AI query failed', true);
            }
        }

        function clearAI() {
            aiResponseDiv.innerHTML = '<span style="color: #888;">Ready for your question.</span>';
            aiPromptInput.value = '';
        }

        document.getElementById('saveAuthorBtn').addEventListener('click', saveAuthor);
        document.getElementById('clearAuthorBtn').addEventListener('click', clearAuthor);
        document.getElementById('saveReviewerBtn').addEventListener('click', saveReviewer);
        document.getElementById('loadReviewerBtn').addEventListener('click', loadReviewer);
        document.getElementById('askAiBtn').addEventListener('click', askAI);
        document.getElementById('clearAiBtn').addEventListener('click', clearAI);
        
        aiPromptInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                askAI();
            }
        });

        loadAuthorEntries();
        loadReviewer();
    </script>
</body>
</html>