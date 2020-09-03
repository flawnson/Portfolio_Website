// var preloader = document.getElementById('loader');
// function preLoaderHandler(){
//     preloader.style.display = 'none';
// }

$(document).ready(function() {
//Preloader
    preloaderFadeOutTime = 1000;
    function hidePreloader() {
        var preloader = $('#preloader');
        preloader.fadeOut(preloaderFadeOutTime);
    }
    hidePreloader();
});

// hidePreloader
// $(window).load(function() {
//     $('#preloader').fadeOut('slow');
// });

function darkMode() {
    var element = document.body;
    element.classList.toggle("dark-mode");
    console.log("pressed")
}
