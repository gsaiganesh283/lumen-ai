<?php
require __DIR__ . '/_init.php';
$admin = require_admin('settings');
if ($admin['role'] !== 'superadmin') {
    http_response_code(403);
    exit('Only superadmins can manage AI providers.');
}

$providers = [
    'anthropic' => ['Claude (Anthropic)', 'claude-sonnet-5-5, claude-opus-5-5, claude-haiku-5-5', 'console.anthropic.com'],
    'openai' => ['OpenAI (ChatGPT)', 'gpt-4.1-mini, gpt-4.1, gpt-4o, gpt-4o-mini, o4-mini', 'platform.openai.com/api-keys'],
    'gemini' => ['Google Gemini', 'gemini-3-flash-preview, gemini-3-pro-preview, gemini-2.5-flash', 'aistudio.google.com/apikey'],
    'groq' => ['Groq', 'openai/gpt-oss-120b, openai/gpt-oss-20b, qwen/qwen3.8-27b', 'console.groq.com/keys'],
    'deepseek' => ['DeepSeek', 'deepseek-chat, deepseek-reasoner', 'platform.deepseek.com'],
    'mistral' => ['Mistral', 'mistral-large-latest, mistral-small-latest', 'console.mistral.ai'],
    'xai' => ['xAI (Grok)', 'grok-4, grok-3-mini', 'console.x.ai'],
    'openrouter' => ['OpenRouter (many models)', 'openrouter/auto', 'openrouter.ai/keys'],
    'custom' => ['Custom OpenAI-compatible', 'llama3.1', ''],
];

$keys = json_decode((string)setting('ai_provider_keys'), true);
$models = json_decode((string)setting('ai_provider_models'), true);
$keys = is_array($keys) ? $keys : [];
$models = is_array($models) ? $models : [];

function test_ai_provider(string $id, array $providers, array $keys, array $models): string {
    if ($id === 'custom') {
        $url = rtrim((string)setting('ai_custom_base_url'), '/') . '/chat/completions';
    } else {
        $urls = [
            'anthropic' => 'https://api.anthropic.com/v1/messages',
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
            'groq' => 'https://api.groq.com/openai/v1/chat/completions',
            'deepseek' => 'https://api.deepseek.com/chat/completions',
            'mistral' => 'https://api.mistral.ai/v1/chat/completions',
            'xai' => 'https://api.x.ai/v1/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
        ];
        $url = $urls[$id] ?? '';
    }
    $key = (string)($keys[$id] ?? '');
    $model = (string)($models[$id] ?? trim(explode(',', $providers[$id][1])[0]));
    if ($id !== 'custom' && $key === '') return 'Not configured';
    if ($url === '') return 'Custom provider URL is not configured';
    if ($id === 'gemini') {
        $list = curl_init('https://generativelanguage.googleapis.com/v1beta/models');
        curl_setopt_array($list, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $key],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        $list_response = curl_exec($list);
        $list_error = curl_error($list);
        $list_status = (int)curl_getinfo($list, CURLINFO_HTTP_CODE);
        curl_close($list);
        if ($list_response === false) {
            return 'Could not list Gemini models' . ($list_error ? ': ' . $list_error : '.');
        }
        $available = json_decode($list_response, true);
        if ($list_status < 200 || $list_status >= 300 || !is_array($available)) {
            $message = is_array($available)
                ? ($available['error']['message'] ?? '')
                : '';
            return 'Model list failed: HTTP ' . $list_status
                . ($message ? ': ' . mb_substr((string)$message, 0, 160) : '');
        }
        $supported = [];
        foreach (($available['models'] ?? []) as $candidate) {
            if (in_array('generateContent', $candidate['supportedGenerationMethods'] ?? [], true)) {
                $name = (string)($candidate['name'] ?? '');
                if (str_starts_with($name, 'models/')) {
                    $supported[] = substr($name, 7);
                }
            }
        }
        if (!in_array($model, $supported, true)) {
            return 'Model is not available for this API key. Use one of: '
                . implode(', ', array_slice($supported, 0, 12));
        }
        $url = str_replace('{model}', rawurlencode($model), $url);
    }

    $headers = ['Content-Type: application/json', 'User-Agent: InterviewLens-Superadmin/1.0'];
    if ($id === 'anthropic') {
        $headers[] = 'x-api-key: ' . $key;
        $headers[] = 'anthropic-version: 2023-06-01';
        $body = ['model' => $model, 'max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'Reply with OK.']]];
    } elseif ($id === 'gemini') {
        $headers[] = 'x-goog-api-key: ' . $key;
        $body = ['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with OK.']]]], 'generationConfig' => ['maxOutputTokens' => 16]];
    } else {
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
        $body = ['model' => $model, 'messages' => [['role' => 'user', 'content' => 'Reply with OK.']], 'max_tokens' => 16];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) {
        if (stripos($error, 'timed out') !== false) {
            return 'Connection timed out. The hosting server may block outbound HTTPS '
                . 'requests to Google Gemini.';
        }
        return 'Connection failed' . ($error ? ': ' . $error : '.');
    }
    if ($status < 200 || $status >= 300) {
        $detail = json_decode($response, true);
        $message = is_array($detail) ? ($detail['error']['message'] ?? $detail['message'] ?? '') : '';
        return 'HTTP ' . $status . ($message ? ': ' . mb_substr((string)$message, 0, 120) : '');
    }
    return 'Connection successful';
}

if (admin_post('settings') && input('action') === 'test_provider') {
    $id = input('provider');
    if (!isset($providers[$id])) {
        flash('Unknown AI provider.', 'danger');
    } else {
        $result = test_ai_provider($id, $providers, $keys, $models);
        flash($providers[$id][0] . ': ' . $result,
            $result === 'Connection successful' ? 'success' : 'danger');
    }
    redirect('ai_providers.php');
}

if (admin_post('ai_providers')) {
    $next_keys = [];
    $next_models = [];
    foreach ($providers as $id => [, $defaults]) {
        $key = trim((string)($_POST['key_' . $id] ?? ''));
        if ($key !== '') $next_keys[$id] = $key;
        elseif (isset($keys[$id])) $next_keys[$id] = $keys[$id];
        $model = trim((string)($_POST['model_' . $id] ?? ''));
        if ($model !== '') $next_models[$id] = $model;
    }
    settings_set([
        'ai_provider_keys' => json_encode($next_keys, JSON_UNESCAPED_SLASHES),
        'ai_provider_models' => json_encode($next_models, JSON_UNESCAPED_SLASHES),
        'ai_active_provider' => input('active_provider', 'anthropic'),
        'ai_fallback' => isset($_POST['fallback']) ? '1' : '0',
        'ai_custom_base_url' => input('custom_base_url'),
    ]);
    audit('ai_providers_save', 'settings', 'AI Providers', 'Updated shared provider configuration');
    flash('AI provider configuration saved. Licensed desktop apps receive it on their next check-in.', 'success');
    redirect('ai_providers.php');
}

admin_start('AI providers', 'ai_providers.php');
?>
<form method="post" class="card" style="max-width:980px"><?= csrf_field() ?>
  <h2>🤖 AI providers</h2>
  <p class="hint">These shared settings are delivered to licensed desktop applications on their next check-in. API keys are encrypted in the database and never displayed here.</p>
  <label>Provider used for answers</label>
  <select name="active_provider">
    <?php foreach ($providers as $id => [$name]): ?><option value="<?= e($id) ?>" <?= setting('ai_active_provider') === $id ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
  </select>
  <label class="check" style="margin-top:14px"><input type="checkbox" name="fallback" <?= setting('ai_fallback') ? 'checked' : '' ?>> <b>Try another configured provider if the selected provider fails</b></label>
  <div class="grid grid-2" style="margin-top:14px">
  <?php foreach ($providers as $id => [$name, $defaults, $help]): ?>
    <div class="card" style="margin:0">
      <h3><?= e($name) ?></h3>
      <?php if ($help): ?><div class="hint"><?= e($help) ?></div><?php endif; ?>
      <label>API key</label>
      <div class="actions"><input type="password" name="key_<?= e($id) ?>" autocomplete="new-password" placeholder="<?= isset($keys[$id]) ? 'Saved — leave blank to keep' : 'Not configured' ?>">
      <button class="btn btn-ghost" name="action" value="test_provider" type="submit" formaction="ai_providers.php?provider=<?= e($id) ?>">Test</button></div>
      <label>Model</label>
      <input name="model_<?= e($id) ?>" value="<?= e($models[$id] ?? trim(explode(',', $defaults)[0])) ?>">
    </div>
  <?php endforeach; ?>
  </div>
  <label>Custom provider base URL</label>
  <input name="custom_base_url" value="<?= e(setting('ai_custom_base_url')) ?>" placeholder="https://example.com/v1">
  <div class="hint">The desktop app shows provider names and connection status only; keys and model settings are managed here.</div>
  <br><button class="btn">Save AI provider settings</button>
</form>
<?php admin_end(); ?>
