<dialog id="supportModal" class="modal">
    <div class="modal__head">
        <h2>Contact Support</h2>
        <button type="button" class="modal__close" id="supportModalClose" aria-label="Close">
            <?php render_icon('x'); ?>
        </button>
    </div>
    <div class="modal__body">
        <p>Need a hand with a booking or your stay? Reach out and we'll get back to you as soon as we can.</p>
        <a class="modal__contact" href="mailto:support@example.com">
            <span class="modal__contact-icon"><?php render_icon('mail'); ?></span> support@example.com
        </a>
        <a class="modal__contact" href="tel:+639000000000">
            <span class="modal__contact-icon"><?php render_icon('phone'); ?></span> +63 900 000 0000
        </a>
        <p class="modal__note">Prefer to send written feedback instead? <a href="feedback.php">Go to Feedback</a>.</p>
    </div>
</dialog>
