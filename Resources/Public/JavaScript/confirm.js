document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-confirm]');
    if (el && !confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
    }
});
