# Welcome to Irons In The Fire

This is a faily simple website to showcase some of the "irons" I have in the fire.
It includes links to projects I am working on, and my resume at the moment. I will add to it as I think of more thing others may be interested in. 
*Suggestions welcome!*

## Private notes

Notes and todo lists live at `/notes/` and are not linked from the public pages. They are stored in MySQL.

1. Create a MySQL database and a user that can use it.
2. Copy `.env.example` to `.env` and fill in the database settings.
3. Run `npm install`, then `npm start`.
4. Open `http://localhost:3000/notes/` and choose a passphrase on the first visit.

The Journal section uses a second password. Diary entries are encrypted in the browser before they are saved, and MySQL stores only ciphertext. That journal password is never sent to the server. The notes passphrase can open the log, and it still cannot read the journal. 
