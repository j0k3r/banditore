(function (window, document) {
    // close alert messages
    document.querySelectorAll('span.close').forEach(function (closeButton) {
        closeButton.addEventListener('click', function (event) {
            event
                .srcElement // font awesome element
                .parentElement // span element
                .parentElement // alert element
                .style.display = 'none'
        }, false)
    })

    // handle rwd menu
    var menu = document.getElementById('menu'),
    WINDOW_CHANGE_EVENT = ('onorientationchange' in window) ? 'orientationchange':'resize';

    function toggleHorizontal() {
        [].forEach.call(
            document.getElementById('menu').querySelectorAll('.menu-can-transform'),
            function(el){
                el.classList.toggle('pure-menu-horizontal');
            }
        );
    };

    function toggleMenu() {
        // set timeout so that the panel has a chance to roll up
        // before the menu switches states
        if (menu.classList.contains('open')) {
            setTimeout(toggleHorizontal, 500);
        } else {
            toggleHorizontal();
        }

        menu.classList.toggle('open');
        document.getElementById('toggle').classList.toggle('x');
    };

    function closeMenu() {
        if (menu.classList.contains('open')) {
            toggleMenu();
        }
    }

    document.getElementById('toggle').addEventListener('click', function (e) {
        toggleMenu();
        e.preventDefault();
    });

    window.addEventListener(WINDOW_CHANGE_EVENT, closeMenu);
})(this, this.document);
