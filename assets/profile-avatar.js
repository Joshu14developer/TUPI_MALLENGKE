/* ============================================================
   PROFILE PHOTO INTERACTION
   ------------------------------------------------------------
   Click any [data-profile-avatar] circle to choose a new photo.
   The file goes to php/profile_upload.php and every avatar on the
   page updates immediately, including the small header portrait.
   ============================================================ */
(function () {
    'use strict';

    var MAX_BYTES = 3 * 1024 * 1024;
    var busy = false;
    var toastTimer = 0;

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function toast(message, isError) {
        var el = document.getElementById('profileAvatarToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'profileAvatarToast';
            el.className = 'profile-avatar-toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }

        el.textContent = message;
        el.classList.toggle('is-error', !!isError);
        el.classList.add('show');

        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            el.classList.remove('show');
        }, 3400);
    }

    function syncRemoveButtons() {
        var hasPhoto = !!document.querySelector('[data-profile-avatar].has-photo');
        document.querySelectorAll('[data-profile-avatar-remove]').forEach(function (button) {
            button.hidden = !hasPhoto;
        });
    }

    function paintAvatar(container, url) {
        var image = container.querySelector('img.profile-avatar-img');
        var initials = container.querySelector('.profile-avatar-initials');

        container.classList.toggle('has-photo', !!url);

        if (url) {
            if (initials) initials.remove();
            if (!image) {
                image = document.createElement('img');
                image.className = 'profile-avatar-img';
                image.alt = '';
                container.insertBefore(image, container.firstChild);
            }
            image.src = url;
        } else {
            if (image) image.remove();
            if (!initials) {
                initials = document.createElement('span');
                initials.className = 'profile-avatar-initials';
                container.insertBefore(initials, container.firstChild);
            }
            initials.textContent = container.getAttribute('data-avatar-initials') || '';
        }
    }

    function applyPhoto(url) {
        document.querySelectorAll('[data-profile-avatar]').forEach(function (container) {
            paintAvatar(container, url);
        });

        document.querySelectorAll('[data-profile-avatar-display]').forEach(function (slot) {
            slot.textContent = '';
            if (url) {
                var image = document.createElement('img');
                image.className = 'header-avatar-img';
                image.alt = '';
                image.src = url;
                slot.appendChild(image);
            } else {
                var icon = document.createElement('i');
                icon.className = slot.getAttribute('data-avatar-fallback') || 'fas fa-user';
                slot.appendChild(icon);
            }
        });

        syncRemoveButtons();
    }

    function endpointFor(avatar) {
        return avatar ? (avatar.getAttribute('data-avatar-endpoint') || '') : '';
    }

    function tokenFor(avatar) {
        return avatar ? (avatar.getAttribute('data-avatar-token') || '') : '';
    }

    function request(avatar, formData) {
        var endpoint = endpointFor(avatar);
        if (!endpoint) {
            return Promise.reject(new Error('The upload address is missing. Refresh the page and try again.'));
        }

        return fetch(endpoint, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.text().then(function (text) {
                var data = {};
                try { data = JSON.parse(text); } catch (e) { data = {}; }
                if (!response.ok || !data.ok) {
                    throw new Error(data.error || 'The photo could not be saved. Please try again.');
                }
                return data;
            });
        });
    }

    function setBusy(avatar, value) {
        busy = value;
        if (!avatar) return;
        avatar.classList.toggle('is-uploading', value);
        avatar.setAttribute('aria-busy', value ? 'true' : 'false');
    }

    function uploadPhoto(avatar, file, input) {
        if (busy) return;

        var data = new FormData();
        data.append('action', 'upload');
        data.append('avatar', file);
        data.append('token', tokenFor(avatar));

        setBusy(avatar, true);

        request(avatar, data).then(function (response) {
            applyPhoto(response.url || '');
            toast(response.message || 'Profile photo updated.');
        }).catch(function (error) {
            toast(error.message, true);
        }).finally(function () {
            setBusy(avatar, false);
            if (input) input.value = '';
        });
    }

    function removePhoto(avatar) {
        if (busy || !avatar) return;
        if (!window.confirm('Remove your profile photo?')) return;

        var data = new FormData();
        data.append('action', 'remove');
        data.append('token', tokenFor(avatar));

        setBusy(avatar, true);

        request(avatar, data).then(function (response) {
            applyPhoto('');
            toast(response.message || 'Profile photo removed.');
        }).catch(function (error) {
            toast(error.message, true);
        }).finally(function () {
            setBusy(avatar, false);
        });
    }

    function setupAvatar(avatar) {
        if (avatar.getAttribute('data-avatar-ready') === '1') return;
        avatar.setAttribute('data-avatar-ready', '1');

        var input = avatar.querySelector('.profile-avatar-input');
        if (!input) {
            input = document.createElement('input');
            input.type = 'file';
            input.className = 'profile-avatar-input';
            input.accept = 'image/jpeg,image/png,image/webp,image/gif';
            input.hidden = true;
            avatar.appendChild(input);
        }

        if (!avatar.hasAttribute('tabindex')) avatar.tabIndex = 0;
        if (!avatar.hasAttribute('role')) avatar.setAttribute('role', 'button');
        if (!avatar.hasAttribute('aria-label')) avatar.setAttribute('aria-label', 'Change profile photo');

        avatar.addEventListener('click', function (event) {
            if (event.target === input || busy) return;
            input.click();
        });

        avatar.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            if (!busy) input.click();
        });

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;

            if (!/^image\/(jpeg|jpg|png|webp|gif)$/i.test(file.type || '')) {
                toast('Please choose a JPG, PNG, WebP or GIF image.', true);
                input.value = '';
                return;
            }
            if (file.size > MAX_BYTES) {
                toast('That photo is larger than 3 MB.', true);
                input.value = '';
                return;
            }

            uploadPhoto(avatar, file, input);
        });
    }

    ready(function () {
        document.querySelectorAll('[data-profile-avatar]').forEach(setupAvatar);

        document.querySelectorAll('[data-profile-avatar-pick]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (busy) return;
                var scope = button.closest('[data-profile-uploader]');
                var avatar = scope ? scope.querySelector('[data-profile-avatar]') : document.querySelector('[data-profile-avatar]');
                if (!avatar) return;
                var input = avatar.querySelector('.profile-avatar-input');
                if (input) input.click();
            });
        });

        document.querySelectorAll('[data-profile-avatar-remove]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (busy) return;
                var scope = button.closest('[data-profile-uploader]');
                var avatar = scope ? scope.querySelector('[data-profile-avatar]') : document.querySelector('[data-profile-avatar]');
                removePhoto(avatar);
            });
        });

        syncRemoveButtons();
    });
})();
