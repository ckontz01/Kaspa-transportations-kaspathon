<?php
declare(strict_types=1);
?>
        </div> <!-- .container -->
    </main>

    <footer class="app-footer">
        <div class="container footer-inner">
            <span>&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?>. All rights reserved.</span>
            <span class="footer-meta">Internal UCY project. Not for production use.</span>
        </div>
    </footer>
</div> <!-- .app -->

<!-- Leaflet JS (load this first so L is available to our map helpers) -->
<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin="">
</script>

<!-- Core JS -->
<script src="<?php echo e(url('assets/js/main.js')); ?>"></script>
<script src="<?php echo e(url('assets/js/forms.js')); ?>"></script>
<script src="<?php echo e(url('assets/js/map.js')); ?>"></script>

</body>
</html>
