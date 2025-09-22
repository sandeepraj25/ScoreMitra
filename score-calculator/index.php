<?php
// --- 1. Session Start & Variable Initialization ---
session_start();

$results = null;
$post_data = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store submitted data in session
    $_SESSION['post_data'] = $_POST;

    // --- 2. Get Inputs & Initialize ---
    $url = $_POST['answerKeyUrl'] ?? '';
    $category = $_POST['category'] ?? 'UR';
    $errorMessage = '';

    // --- 3. Fetch HTML Content ---
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        $errorMessage = 'Please provide a valid Answer Key URL.';
    } else {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]);
        $htmlContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode != 200 || !$htmlContent) {
            $errorMessage = "Could not fetch content from the URL (HTTP Status: {$httpCode}). The page may be down or blocking requests.";
        }
    }

    // --- 4. Parse HTML if Fetch Succeeded ---
    if (empty($errorMessage)) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($htmlContent);
        $xpath = new DOMXPath($dom);

        // --- Extract Student Details and Photos---
        $student_details = [];
        $detail_labels = ['Roll Number', 'Registration Number', 'Candidate Name', 'Test Center Name', 'Test Date', 'Test Time', 'Subject'];
        foreach ($detail_labels as $label) {
            $node = $xpath->query("//td[normalize-space(text())='{$label}']/following-sibling::td");
            $student_details[$label] = $node->length > 0 ? trim($node->item(0)->textContent) : 'N/A';
        }
        $student_details['Category'] = $category; // Add selected category to details
        $photo_node = $xpath->query("//td[normalize-space(text())='Application Photograph']/following-sibling::td//img/@src");
        $student_details['Application Photograph'] = $photo_node->length > 0 ? $photo_node->item(0)->nodeValue : '';
        $photo_node = $xpath->query("//td[normalize-space(text())='Exam Day Photograph']/following-sibling::td//img/@src");
        $student_details['Exam Day Photograph'] = $photo_node->length > 0 ? $photo_node->item(0)->nodeValue : '';


        // --- 5. Count Scores Section-wise ---
        $questionPanels = $xpath->query("//div[contains(@class, 'question-pnl')]");
        if ($questionPanels->length > 0) {
            $sections = [];
            foreach ($questionPanels as $panel) {
                $sectionNode = $xpath->query("preceding-sibling::div[contains(@class, 'section-lbl')][1]//span[@class='bold']", $panel);
                $sectionName = $sectionNode->length > 0 ? trim($sectionNode->item(0)->textContent) : 'Unknown Section';
                if (!isset($sections[$sectionName])) {
                    $sections[$sectionName] = ['total' => 0, 'correct' => 0, 'wrong' => 0, 'unattempted' => 0];
                }
                $chosenNode = $xpath->query(".//td[contains(text(), 'Chosen Option :')]/following-sibling::td", $panel);
                $chosenOption = $chosenNode->length > 0 ? trim($chosenNode->item(0)->textContent) : '--';
                $correctOptionNode = $xpath->query(".//img[contains(@src, 'tick.png')]/ancestor::td[1]", $panel);
                $correctOptionText = $correctOptionNode->length > 0 ? trim($correctOptionNode->item(0)->textContent) : '';
                $correctOption = preg_replace('/[^0-9]/', '', $correctOptionText);
                $sections[$sectionName]['total']++;
                if ($chosenOption === '--' || $chosenOption === '') $sections[$sectionName]['unattempted']++;
                elseif ($chosenOption == $correctOption) $sections[$sectionName]['correct']++;
                else $sections[$sectionName]['wrong']++;
            }
            $_SESSION['results'] = ['student_details' => $student_details, 'sections' => $sections];
        } else {
            $errorMessage = "Could not parse questions from the page. The structure might be unusual or unsupported.";
        }
    }
    
    if (!empty($errorMessage)) {
        $_SESSION['results'] = ['error' => $errorMessage];
    }

    // --- PRG Pattern: Redirect to avoid form resubmission ---
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- Load data from session on GET request or refresh ---
if (isset($_SESSION['results'])) {
    $results = $_SESSION['results'];
    $post_data = $_SESSION['post_data'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Score Calculator</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8f9fa; margin: 0; padding: 20px; }
    .container { max-width: 800px; margin: 20px auto; }
    .form-container { background: #fff; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border-radius: 10px; }
    .form-group { margin-bottom: 20px; }
    label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; }
    input[type="text"], select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
    button { display: block; width: 100%; padding: 12px; background: #007bff; color: #fff; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: 600; }
    #result-container { margin-top: 30px; }
    .scorecard { border: 2px solid #A9A9A9; border-radius: 8px; overflow: hidden; background: #fff; }
    .scorecard-header { padding: 15px; text-align: center; border-bottom: 2px solid #A9A9A9; }
    .scorecard-header h2 { margin: 0; font-size: 20px; } .scorecard-header h3 { margin: 5px 0; font-size: 16px; font-weight: 500; }
    .details-table, .results-table { width: 100%; border-collapse: collapse; }
    .details-table td { padding: 8px 15px; border-bottom: 1px solid #eee; }
    .details-table td:first-child { font-weight: 600; width: 30%; }
    .results-table th, .results-table td { padding: 12px; text-align: center; border: 1px solid #ddd; }
    .results-table th { background-color: #f2f2f2; font-weight: 600; }
    .results-table td { font-weight: 500; }
    .error-box { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; text-align: center; }
    .notes { padding: 15px; font-size: 14px; background-color: #f1f1f1; border-top: 2px solid #A9A9A9; }
  </style>
</head>
<body>

<div class="container">
  <div class="form-container">
    <h2>RRB NTPC Score Calculator</h2>
    <form id="scoreForm" method="post" action="">
      <div class="form-group">
        <label for="answerKeyUrl">Answer Key URL</label>
        <input type="text" id="answerKeyUrl" name="answerKeyUrl" required value="<?php echo htmlspecialchars($post_data['answerKeyUrl'] ?? ''); ?>">
      </div>
      <div class="form-group">
          <label for="category">Category</label>
          <select id="category" name="category">
              <?php
                $categories = ['UR', 'OBC', 'SC', 'ST', 'EWS'];
                $selected_category = $post_data['category'] ?? 'UR';
                foreach ($categories as $cat) {
                    $selected = ($cat === $selected_category) ? 'selected' : '';
                    echo "<option value=\"{$cat}\" {$selected}>{$cat}</option>";
                }
              ?>
          </select>
      </div>
      <button type="submit">Check My Score</button>
    </form>
  </div>

  <div id="result-container">
    <?php if ($results !== null): ?>
        <?php if (isset($results['error'])): ?>
            <div class="error-box"><strong>Error:</strong> <?php echo htmlspecialchars($results['error']); ?></div>
        <?php else: 
            $details = $results['student_details'];
            $sections = $results['sections'];
            $grand_total = ['total' => 0, 'correct' => 0, 'wrong' => 0, 'marks' => 0.0];
        ?>
            <div class="scorecard">
                <div class="scorecard-header">
                    <h2>रेलवे भर्ती बोर्ड / RAILWAY RECRUITMENT BOARDS</h2>
                    <h3><?php echo htmlspecialchars($details['Subject'] ?? 'N/A'); ?></h3>
                </div>
                <table class="details-table">
                    <?php foreach ($details as $label => $value): 
                        if ($label === 'Subject') continue;
                    ?>
                    <tr>
                        <td><?php echo $label; ?></td>
                        <td>
                            <?php if (strpos($label, 'Photograph') !== false && !empty($value)): ?>
                                <img src="<?php echo htmlspecialchars($value); ?>" style="max-width: 120px; height: auto;">
                            <?php else: ?>
                                <?php echo htmlspecialchars($value); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <table class="results-table">
                    <thead>
                        <tr><th>Section</th><th>Total</th><th>Right</th><th>Wrong</th><th>Marks</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sections as $name => $data): 
                            $marks = ($data['correct'] * 1) - ($data['wrong'] * (1/3));
                            $grand_total['total'] += $data['total'];
                            $grand_total['correct'] += $data['correct'];
                            $grand_total['wrong'] += $data['wrong'];
                            $grand_total['marks'] += $marks;
                        ?>
                        <tr>
                            <td style="text-align: left; padding-left: 15px;"><?php echo $name; ?></td>
                            <td><?php echo $data['total']; ?></td>
                            <td><?php echo $data['correct']; ?></td>
                            <td><?php echo $data['wrong']; ?></td>
                            <td><?php echo number_format($marks, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="background-color: #fffbd8; font-weight: bold;">
                            <td>Total</td>
                            <td><?php echo $grand_total['total']; ?></td>
                            <td><?php echo $grand_total['correct']; ?></td>
                            <td><?php echo $grand_total['wrong']; ?></td>
                            <td><?php echo number_format($grand_total['marks'], 2); ?></td>
                        </tr>
                    </tbody>
                </table>
                <div class="notes">
                    <strong>* Note</strong><br>
                    Correct Answer will carry 1 mark per Question.<br>
                    Incorrect Answer will carry 1/3 Negative mark per Question.
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

</body>
</html>