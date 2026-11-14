(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.dwdc-copy');
        if (!button) {
            return;
        }
        var input = document.getElementById(button.getAttribute('data-target'));
        if (!input) {
            return;
        }
        var original = button.textContent;
        var done = function () {
            button.textContent = button.getAttribute('data-copied') || original;
            window.setTimeout(function () {
                button.textContent = original;
            }, 1600);
        };
        input.focus();
        input.select();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done).catch(function () {
                document.execCommand('copy');
                done();
            });
            return;
        }
        document.execCommand('copy');
        done();
    });
})();
