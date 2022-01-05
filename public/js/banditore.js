(function (window, document) {
    // close alert messages
    var alerts = document.querySelectorAll('span.close')
    for (var i = 0; i < alerts.length; ++i) {
        alerts[i].addEventListener('click', function (event) {
            // in case the font awesome element isn't loaded (might be the case on iOS)
            if (event.target.className === 'fa fa-close') {
                event
                    .target // font awesome element
                    .parentElement // span element
                    .parentElement // alert element
                    .style.display = 'none'
            } else {
                event
                    .target // span element
                    .parentElement // alert element
                    .style.display = 'none'
            }
        }, false)
    }

    // handle rwd menu
    var menu = document.getElementById('menu'),
    WINDOW_CHANGE_EVENT = ('onorientationchange' in window) ? 'orientationchange':'resize'

    function toggleHorizontal() {
        [].forEach.call(
            document.getElementById('menu').querySelectorAll('.menu-can-transform'),
            function(el){
                el.classList.toggle('pure-menu-horizontal')
            }
        )
    }

    function toggleMenu() {
        // set timeout so that the panel has a chance to roll up
        // before the menu switches states
        if (menu.classList.contains('open')) {
            setTimeout(toggleHorizontal, 500)
        } else {
            toggleHorizontal()
        }

        menu.classList.toggle('open')
        document.getElementById('toggle').classList.toggle('x')
    }

    function closeMenu() {
        if (menu.classList.contains('open')) {
            toggleMenu()
        }
    }

    document.getElementById('toggle').addEventListener('click', function (e) {
        toggleMenu()
        e.preventDefault()
    })

    window.addEventListener(WINDOW_CHANGE_EVENT, closeMenu)
})(this, this.document)
