/* Live preview for the optional profile photo on registration forms.
   If JavaScript is off, the file input is still submitted normally. */
(function () {
    'use strict';

    var MAX_BYTES = 3 * 1024 * 1024;
    var ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    var ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    function formatSize(bytes) {
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    document.querySelectorAll('[data-reg-photo]').forEach(function (root) {
        var input = root.querySelector('[data-reg-photo-input]');
        if (!input) return;

        var preview = root.querySelector('[data-reg-photo-preview]');
        var image = root.querySelector('[data-reg-photo-img]');
        var placeholder = root.querySelector('[data-reg-photo-placeholder]');
        var name = root.querySelector('[data-reg-photo-name]');
        var remove = root.querySelector('[data-reg-photo-remove]');
        var error = root.querySelector('[data-reg-photo-error]');
        var objectUrl = '';

        function showError(message) {
            if (!error) return;
            error.textContent = message || '';
            error.hidden = !message;
        }

        function clearPreview() {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = '';
            }
            if (image) {
                image.removeAttribute('src');
                image.hidden = true;
            }
            if (placeholder) placeholder.hidden = false;
            if (name) name.textContent = 'No photo selected';
            if (remove) remove.hidden = true;
            root.classList.remove('has-photo');
        }

        function reset() {
            input.value = '';
            clearPreview();
            showError('');
        }

        function readFile(file) {
            clearPreview();
            showError('');
            if (!file) return;

            var ext = (file.name.split('.').pop() || '').toLowerCase();
            var typeOk = !file.type || ALLOWED_TYPES.indexOf(file.type) !== -1;
            if (!typeOk || ALLOWED_EXT.indexOf(ext) === -1) {
                showError('Choose a JPG, PNG, WebP or GIF image.');
                input.value = '';
                return;
            }
            if (!file.size) {
                showError('That file is empty. Choose another photo.');
                input.value = '';
                return;
            }
            if (file.size > MAX_BYTES) {
                showError('That photo is ' + formatSize(file.size) + '. Choose one under 3 MB.');
                input.value = '';
                return;
            }

            objectUrl = URL.createObjectURL(file);
            if (image) {
                image.src = objectUrl;
                image.hidden = false;
            }
            if (placeholder) placeholder.hidden = true;
            if (name) name.textContent = file.name;
            if (remove) remove.hidden = false;
            root.classList.add('has-photo');
        }

        input.addEventListener('change', function () {
            readFile(input.files && input.files[0]);
        });

        if (remove) {
            remove.addEventListener('click', function () {
                reset();
                if (preview) preview.focus();
            });
        }

        if (preview) {
            preview.addEventListener('click', function () {
                input.click();
            });
            preview.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    input.click();
                }
            });
        }

        window.addEventListener('beforeunload', function () {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        });
    });
})();
