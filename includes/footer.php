<?php $user = current_user(); ?>
<?php if ($user): ?>
    </main>
  </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if (!empty($extra_js)): foreach ((array)$extra_js as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; endif; ?>
<?php if (!empty($extra_js_inline)): ?>
<script><?= $extra_js_inline ?></script>
<?php endif; ?>
</body>
</html>
