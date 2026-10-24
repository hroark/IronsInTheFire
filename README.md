# Welcome to Irons In The Fire

This is a faily simple website to showcase some of the "irons" I have in the fire.
It includes links to projects I am working on, and my resume at the moment. I will add to it as I think of more thing others may be interested in. 
*Suggestions welcome!*

## Private notes

Notes live at `public_html/notes` and are not linked from the public pages. The hosted page reads `notes/config.php`. It does not look for a `.env` file or a `.env` folder.

The MySQL database is named `porter_notes`. In `config.php`, set the host, that database name, the user, and the password. `MYSQL_HOST` and `MYSQL_DATABASE` lines are accepted in that file. Open `/notes/` and choose a sign-in password the first time.

`npm start` is only for running the site on your own computer. That local server is the one that reads a `.env` file copied from `.env.example`. 
