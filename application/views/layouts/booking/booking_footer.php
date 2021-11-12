<div id="frame-footer">
    <small>
        <span class="footer-powered-by">
            Powered By
    
            <a href="https://easyappointments.org" target="_blank">Easy!Appointments</a>
        </span>

        <span class="footer-options">
            <span id="select-language" class="badge badge-secondary">
                <i class="fas fa-language mr-2"></i>
                <?= ucfirst(config('language')) ?>
            </span>
    
            <a class="backend-link badge badge-primary" href="<?= site_url('backend'); ?>">
                <i class="fas fa-sign-in-alt mr-2"></i>
                <?= session('user_id') ? lang('backend_section') : lang('login') ?>
            </a>
        </span>
    </small>
</div>
