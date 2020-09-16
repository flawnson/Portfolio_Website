$(document).ready(function() {
//Preloader
    setTimeout(
        function()
        {
            preloaderFadeOutTime = 500;
            function hidePreloader() {
                var preloader = $('#preloader');
                preloader.fadeOut(preloaderFadeOutTime);
            }
            hidePreloader();
        }, 1000);
});

function darkMode() {
    var element = document.body;
    var icons = $(".icon");
    element.classList.toggle("dark-mode");
    icons.toggleClass("dark-mode");
};

window.addEventListener("load",function lastMod() {
    var lastMod = "Last Edited: " + document.lastModified;
    document.getElementById("modTime").innerHTML = lastMod;
},false);

