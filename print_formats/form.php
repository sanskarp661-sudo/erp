<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);
require_once __DIR__ . '/../includes/print_engine.php';

$id = (int)input('id');
$doctypes = pf_doctypes();

$format = [
    'id' => 0, 'name' => '', 'doctype' => input('doctype') ?: array_key_first($doctypes),
    'html_template' => '', 'is_default' => 0,
];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM print_formats WHERE id = ?');
    $stmt->execute([$id]);
    $format = $stmt->fetch();
    if (!$format) {
        flash('danger', 'Print format not found.');
        redirect('/print_formats/index.php');
    }
}

$error = '';

if (is_post()) {
    // If PHP's post_max_size was exceeded, PHP silently empties $_POST and
    // $_FILES (no warning, no exception) while CONTENT_LENGTH still shows
    // what the browser actually sent. That's the #1 cause of "I pasted a
    // large template and it got cut off with no error" on shared hosting
    // — catch it before csrf_verify() (which would otherwise fail here
    // too, with a much less useful "invalid form submission" message).
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
        $error = 'The server rejected this submission as too large ('
            . round($contentLength / 1024 / 1024, 2) . ' MB) before this page saw any of it — '
            . 'nothing was truncated on our end, the whole submission was dropped by a server-side size limit (PHP\'s post_max_size, or a hosting firewall). '
            . 'Try the "Upload a .html file instead" option below, or ask your host to raise post_max_size / upload_max_filesize.';
    } else {
        csrf_verify();
        $name = input('name');
        $doctype = input('doctype');
        $template = $_POST['html_template'] ?? '';

        $uploadError = '';
        if (!empty($_FILES['template_file']['name'])) {
            $fileErr = $_FILES['template_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($fileErr === UPLOAD_ERR_INI_SIZE || $fileErr === UPLOAD_ERR_FORM_SIZE) {
                $uploadError = 'The uploaded file is larger than this server allows (upload_max_filesize). Paste the template directly instead, or ask your host to raise the limit.';
            } elseif ($fileErr === UPLOAD_ERR_OK && is_uploaded_file($_FILES['template_file']['tmp_name'])) {
                $uploaded = file_get_contents($_FILES['template_file']['tmp_name']);
                if ($uploaded !== false && trim($uploaded) !== '') {
                    $template = $uploaded;
                }
            }
        }

        $isDefault = input('is_default') === '1' ? 1 : 0;

        if ($uploadError) {
            $error = $uploadError;
        } elseif ($name === '' || !isset($doctypes[$doctype]) || trim($template) === '') {
            $error = 'Name, a valid document type, and a template (pasted or uploaded) are required.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($id) {
                    $pdo->prepare('UPDATE print_formats SET name=?, doctype=?, html_template=?, updated_at=NOW() WHERE id=?')
                        ->execute([$name, $doctype, $template, $id]);
                    $formatId = $id;
                } else {
                    $pdo->prepare('INSERT INTO print_formats (name, doctype, html_template, created_by) VALUES (?,?,?,?)')
                        ->execute([$name, $doctype, $template, current_user()['id']]);
                    $formatId = (int)$pdo->lastInsertId();
                }
                if ($isDefault) {
                    $pdo->prepare('UPDATE print_formats SET is_default = 0 WHERE doctype = ?')->execute([$doctype]);
                    $pdo->prepare('UPDATE print_formats SET is_default = 1 WHERE id = ?')->execute([$formatId]);
                }
                $pdo->commit();
                flash('success', ($id ? 'Print format updated' : 'Print format created') . ' — ' . number_format(strlen($template)) . ' characters saved.');
                redirect('/print_formats/form.php?id=' . $formatId);
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Could not save print format.';
            }
        }
        $format = ['id' => $id, 'name' => $name, 'doctype' => $doctype, 'html_template' => $template, 'is_default' => $isDefault];
    }
}

$sampleRecords = $id ? pf_sample_records($format['doctype']) : [];

$jsDoctypes = [];
foreach ($doctypes as $key => $cfg) {
    $jsDoctypes[$key] = ['label' => $cfg['label'], 'tokens' => $cfg['tokens'], 'default' => $cfg['default']];
}

$page_title = $id ? 'Edit Print Format' : 'New Print Format';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" id="pfForm" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card p-4">
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label">Format Name</label>
            <input type="text" name="name" class="form-control" required value="<?= e($format['name']) ?>" placeholder="e.g. Sales Invoice - Compact">
          </div>
          <div class="col-sm-6">
            <label class="form-label">Document Type</label>
            <select name="doctype" id="doctypeSelect" class="form-select" <?= $id ? 'disabled' : '' ?> required>
              <?php foreach ($doctypes as $key => $cfg): ?>
                <option value="<?= e($key) ?>" <?= $format['doctype'] === $key ? 'selected' : '' ?>><?= e($cfg['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($id): ?><input type="hidden" name="doctype" value="<?= e($format['doctype']) ?>"><div class="form-text">Document type can't be changed after creation — make a new format instead.</div><?php endif; ?>
          </div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-1">
          <label class="form-label mb-0">HTML Template</label>
          <button type="button" id="loadDefaultBtn" class="btn btn-sm btn-outline-secondary">Load built-in default as starting point</button>
        </div>
        <textarea name="html_template" id="templateTextarea" class="form-control" rows="18" style="font-family:ui-monospace,monospace;font-size:.85rem"><?= e($format['html_template']) ?></textarea>
        <div class="d-flex justify-content-between mt-1">
          <span class="small text-muted">Pasted content is <span id="charCount">0</span> characters. If a large paste gets cut off with no error, use the upload option below instead — it isn't affected by the same limit.</span>
        </div>
        <div class="mt-2">
          <label class="form-label small mb-1">Or upload a .html file instead of pasting</label>
          <input type="file" name="template_file" accept=".html,.htm,.txt" class="form-control form-control-sm">
          <div class="form-text">If both a paste and a file are provided, the uploaded file wins.</div>
        </div>
        <div class="form-check mt-3">
          <input type="checkbox" class="form-check-input" id="isDefaultCheck" name="is_default" value="1" <?= $format['is_default'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="isDefaultCheck">Make this the default format for <span id="doctypeLabelInline"><?= e($doctypes[$format['doctype']]['label']) ?></span></label>
        </div>
        <div class="page-actions mt-4">
          <button type="submit" class="btn btn-brand">Save</button>
          <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card p-3 mb-3">
        <h6 class="mb-2">Available tokens for <span id="doctypeLabel"><?= e($doctypes[$format['doctype']]['label']) ?></span></h6>
        <p class="small text-muted">Type these anywhere in the template — they'll be replaced with the record's actual data when printed.</p>
        <div id="tokenList" class="small"></div>
      </div>

      <?php if ($id): ?>
      <div class="card p-3">
        <h6 class="mb-2">Preview</h6>
        <?php if ($sampleRecords): ?>
          <form method="get" action="<?= base_url('print.php') ?>" target="_blank">
            <input type="hidden" name="doctype" value="<?= e($format['doctype']) ?>">
            <input type="hidden" name="format" value="<?= (int)$format['id'] ?>">
            <select name="id" class="form-select form-select-sm mb-2">
              <?php foreach ($sampleRecords as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= e($r['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-brand w-100"><i class="fa-solid fa-eye"></i> Preview with this record</button>
          </form>
        <?php else: ?>
          <p class="small text-muted mb-0">No <?= e(strtolower($doctypes[$format['doctype']]['label'])) ?> records exist yet to preview against.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</form>
<?php
$extra_js_inline = "
var PF_DOCTYPES = " . json_encode($jsDoctypes) . ";

var templateTa = document.getElementById('templateTextarea');
var charCountEl = document.getElementById('charCount');
function updateCharCount() { charCountEl.textContent = templateTa.value.length.toLocaleString(); }
templateTa.addEventListener('input', updateCharCount);
templateTa.addEventListener('paste', function () { setTimeout(updateCharCount, 0); });
updateCharCount();

function renderTokenList(doctype) {
  var cfg = PF_DOCTYPES[doctype];
  if (!cfg) return;
  document.getElementById('doctypeLabel').textContent = cfg.label;
  document.getElementById('doctypeLabelInline').textContent = cfg.label;
  var html = '';
  Object.keys(cfg.tokens).forEach(function (key) {
    html += '<div class=\"d-flex justify-content-between border-bottom py-1\">' +
      '<code class=\"pf-token\" style=\"cursor:pointer\" data-token=\"{{' + key + '}}\" title=\"Click to insert\">{{' + key + '}}</code>' +
      '<span class=\"text-muted\">' + cfg.tokens[key] + '</span></div>';
  });
  document.getElementById('tokenList').innerHTML = html;
}

var doctypeSelect = document.getElementById('doctypeSelect');
if (doctypeSelect) {
  doctypeSelect.addEventListener('change', function () { renderTokenList(this.value); });
}
renderTokenList(doctypeSelect ? doctypeSelect.value : " . json_encode($format['doctype']) . ");

document.getElementById('tokenList').addEventListener('click', function (e) {
  var el = e.target.closest('.pf-token');
  if (!el) return;
  var ta = document.getElementById('templateTextarea');
  var token = el.getAttribute('data-token');
  var start = ta.selectionStart, end = ta.selectionEnd;
  ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = start + token.length;
});

document.getElementById('loadDefaultBtn').addEventListener('click', function () {
  var doctype = doctypeSelect ? doctypeSelect.value : " . json_encode($format['doctype']) . ";
  var cfg = PF_DOCTYPES[doctype];
  if (!cfg) return;
  if (document.getElementById('templateTextarea').value.trim() !== '' && !confirm('This will replace the current template content. Continue?')) return;
  document.getElementById('templateTextarea').value = cfg.default;
});
";
require __DIR__ . '/../includes/footer.php';
