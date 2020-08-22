$(function () {
    "use strict";
    var e = $(".grid");
    $(window).on("load", function () {
        $(".startLoad").fadeOut("slow"), e.length && e.isotope({
            itemSelector: ".grid .item",
            percentPosition: !0,
            masonry: {columnWidth: ".grid-sizer"}
        })
    }), $(".ul-filter li").on("click", function () {
        var t = $(this).attr("data-filter");
        $(this).addClass("active_filter").siblings().removeClass("active_filter"), e.isotope({filter: t})
    });
    var t = ".page-right", a = "#splitlayout", o = "#menu a", i = ".home_type";

    function s() {
        $(a).addClass("open-right").removeClass("close-right")
    }

    function n() {
        $(a).removeClass("open-right").addClass("close-right reset-layout")
    }

    function r() {
        return Modernizr.mq("(max-width: 991px)") ? "mobile_screen" : "not_mobile_screen"
    }

    function l(e) {
        $(e).length && $(e).height($(window).height())
    }

    function c(e) {
        ++u > e && (u = 0)
    }

    function p() {
        $(t).animate({scrollTop: 0}, 5)
    }

    function g() {
        if ($(window).height() > $(".active_sec").height()) {
            var e = .5 * ($(window).height() - $(".active_sec").height());
            $(".active_sec").css("margin-top", e)
        } else $(".active_sec").css("margin-top", 0)
    }

    function d() {
        $(".navbar-toggle").toggleClass("collapsed"), $(".mob-menu-overlay").fadeToggle(), $(".side-right").toggleClass("right-zero")
    }

    r(), l(".full-height"), $(".mob-menu .navbar-toggle,.mob-menu-overlay").on("click", function () {
        d()
    });
    var m = ["pt-page-moveToRightFade", "pt-page-scaleDown", "pt-page-flipOutRight", "pt-page-rotatePushLeft", "pt-page-rotateFoldLeft", "pt-page-rotateCubeLeftOut pt-page-ontop", "pt-page-rotateSidesOut"],
        f = ["pt-page-moveFromLeftFade", "pt-page-scaleUpDown pt-page-delay300", "pt-page-flipInLeft pt-page-delay500", "pt-page-rotatePullRight pt-page-delay180", "pt-page-moveFromRightFade", "pt-page-rotateCubeLeftIn", "pt-page-rotateSidesIn pt-page-delay200"],
        u = 0;
    $("#menu a:not(.loading)").on("click", function (e) {
        var t = $(this).attr("href");
        this.hash;
        if (e.preventDefault(), $(this).addClass("active_item").parent().siblings().find("a").removeClass("active_item"), "mobile_screen" === r()) if (c(6), d(), "#home" === t) $(i).fadeIn(), $("section").removeClass("active_sec"), n(), C(); else {
            if ($(t).hasClass("active_sec")) return;
            $(o).addClass("loading"), $(i).fadeOut(), s(), p(), $(".pt-page-current").addClass(m[u]), $(t).addClass(f[u]).addClass("pt-page-current active_sec").siblings().removeClass("active_sec")
        } else if ("#home" === t) n(); else if ($(".open-right").length) {
            if ($(t).hasClass("active_sec")) return;
            $(o).addClass("loading"), p(), w(), c(6), $(".pt-page-current").addClass(m[u]), $(t).addClass(f[u]).addClass("pt-page-current active_sec").siblings().removeClass("active_sec"), g()
        } else s(), w(), $(o).addClass("loading"), $(t).addClass("pt-page-current active_sec").siblings().removeClass("pt-page-current active_sec"), g()
    }), $("section").on("animationend webkitAnimationEnd oAnimationEnd MSAnimationEnd", function (e) {
        $(".active_sec").siblings().removeClass("pt-page-current"), $(".pt-page").alterClass("pt-page-m* pt-page-s* pt-page-f* pt-page-r* pt-page-d* pt-page-o*", ""), $(o).removeClass("loading")
    });
    var h = ".project-slider";
    $(h).length && $(h).responsiveSlides({
        nav: !0,
        prevText: '<i class="pe-7s-angle-left"></i>',
        nextText: '<i class="pe-7s-angle-right"></i>'
    }), $(".contact form .submit").on("click", function () {
        $(".contact form .form-control").removeClass("errorForm"), $(".msg_success,.msg_error").css("display", "");
        var e = !1, t = $('.contact form input[type="text"]');
        "" !== t.val() && " " !== t.val() || (e = !0, $(t).addClass("errorForm"));
        var a = $('.contact form input[type="email"]');
        "" === a.val() || " " === a.val() ? ($(a).addClass("errorForm"), e = !0) : /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}$/i.test(a.val()) || ($(a).addClass("errorForm"), e = !0);
        var o = $(".contact form textarea");
        if ("" !== o.val() && " " !== o.val() || (e = !0, $(o).addClass("errorForm")), !0 === e) return !1;
        var i = $(".contact form").serialize();
        return $.ajax({
            type: "POST", url: $(".contact form").attr("action"), data: i, success: function (e) {
                "SENDING" === e ? $(".msg_success").fadeIn("slow") : $(".msg_error").fadeIn("slow")
            }
        }), !1
    }), $(window).on("resize", function () {
        g(), l(".full-height"), "mobile_screen" === r() && ($("[href='#home']").hasClass("active_item") ? $(i).fadeIn() : $(i).fadeOut())
    });
    var v = ".my_img";
    $(v).length && $(v).magnificPopup({
        type: "image",
        removalDelay: 300,
        mainClass: "mfp-with-zoom",
        gallery: {enabled: !0},
        zoom: {
            enabled: !0, duration: 300, easing: "ease-in-out", opener: function (e) {
                return e.is("img") ? e : e.find("img")
            }
        }
    }), $(".info .image_overlay").on("click", function () {
        $(this).parents(".project_content").find(v).trigger("click")
    }), $(".popup-with-zoom-anim").magnificPopup({
        type: "inline",
        fixedContentPos: !1,
        fixedBgPos: !0,
        overflowY: "auto",
        closeBtnInside: !0,
        preloader: !1,
        midClick: !0,
        removalDelay: 300,
        mainClass: "my-mfp-zoom-in"
    }), $(".video-popup").magnificPopup({type: "iframe"});
    var _ = "#typed";
    if ($(_).length) new Typed(_, {
        stringsElement: "#typed-strings",
        typeSpeed: 40,
        backSpeed: 0,
        backDelay: 1500,
        startDelay: 1e3,
        fadeOut: !1,
        loop: !0
    });

    function C() {
        var e = $(".fit__text");
        0 !== e.length && e.fitText(1, {maxFontSize: 45})
    }

    C();
    var y = ".owl";

    function w() {
        $(y).slick("setPosition")
    }

    $(y).slick({
        infinite: !1,
        slidesToShow: 2,
        arrows: !1,
        responsive: [{breakpoint: 768, settings: {slidesToShow: 1}}]
    }), $(".prev-testi").on("click", function () {
        $(y).slick("slickPrev")
    }), $(".next-testi").on("click", function () {
        $(y).slick("slickNext")
    }), $(".matchH").matchHeight();
    var b = ".pogoSlider";
    $(b).length && $(b).pogoSlider({
        autoplay: !0,
        autoplayTimeout: 3e3,
        displayProgess: !1,
        preserveTargetSize: !0,
        generateButtons: !1,
        generateNav: !1,
        responsive: !0
    }).data("plugin_pogoSlider"), $("#bgndVideo").length && jQuery("#bgndVideo").YTPlayer({
        autoPlay: !0,
        startAt: 0,
        showControls: !1,
        opacity: 1
    }), $(".land_intro").height(.7 * $(window).height());
    var x = $(".land__text");
    0 !== x.length && x.fitText(1, {maxFontSize: 85}), $(".options_box .cog").on("click", function () {
        $(".options_box").toggleClass("animate_options_box")
    }), $(".colors_box span").on("click", function () {
        var e = $(this).data("theme");
        $(".colors_box span").removeClass("c__check"), $(this).addClass("c__check"), "default" === e ? $(".new_color").remove() : 0 === $(".new_color").length ? $("head").append('<link rel="stylesheet" href="assets/css/' + e + '" class="new_color">') : $(".new_color").attr("href", "assets/css/" + e)
    }), $(window).on("load", function () {
        setTimeout(function () {
            $(".options_box").removeClass("animate_options_box")
        }, 4e3)
    })
});