# Website

This repository contains the code for my website and blog as hosted on CPanel.

## Hosting
Domains are managed on ~~GoDaddy~~ NameCheap, both .com and .ca domains are registered on my Comend account (TODO: transfer to my personal NameCheap account).
Web hosting is managed by CPanel. You can access the CPanel admin via NameCheap's control panel [here](https://ap.www.namecheap.com/ProductList/HostingSubscriptions).
You'll need to log into CPanel, pull the most recent changes, and deploy HEAD after making changes for them to appear on the production site.

## Libraries and Packages

The main dependencies are Bootstrap 3 (I didn't use 4 because my previous website used 3), fontawesome (and it's cousin, Academicicons), and fonts (Raleway, Roboto, and Karla) supplied by Google's CDN. Everything else is coded from scratch. The home page has a link to my blog.

## Flitter
I've had this problem for many years where I get ideas for tweets but decide not to share them.
Flitter is my solution to this problem.
It's my own custom Twitter client that I use to share ideas and thoughts.
It comes with a small app that I built and installed on my phone that I can use to instantly post something to my website.
### Database
I use cPanel's MySQL database to store my posts.
Initialized with:
```SQL
CREATE TABLE micro_posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  is_published TINYINT(1) NOT NULL DEFAULT 1
);
```
Emoji support:
```SQL
ALTER TABLE micro_posts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
I manually write and upload a small php config script that holds my config info (keeps it off VCS)
### API
I use a custom php CRUD API to post to my website.
All endpoints are in the micro-posts.php file.
### App
I wrote a small iOS app with SwiftUI to post to my website from anywhere.
You can find it in the [Flitter](https://github.com/flawnson/flitter) repo on my GitHub.

# Blog
This is a simple python-rendered markdown blog.
```python
python scripts/build-blog.py
```

# Ideas
[] A questbook for site visitors to leave a note or sticker
