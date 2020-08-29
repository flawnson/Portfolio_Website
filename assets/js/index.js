// var preloader = document.getElementById('loader');
// function preLoaderHandler(){
//     preloader.style.display = 'none';
// }

$(document).ready(function() {
//Preloader
    preloaderFadeOutTime = 500;
    function hidePreloader() {
        var preloader = $('.loader');
        preloader.fadeOut(preloaderFadeOutTime);
    }
    hidePreloader();
});