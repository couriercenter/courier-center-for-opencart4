# 📦 Courier Center για OpenCart — Οδηγός Δημοσίευσης, Auto-Update & Live Eshop

> Γράφτηκε για να το διαβάσεις βήμα-βήμα. Δεν χρειάζεται να ξέρεις προγραμματισμό.
> Όπου λέει «τρέξε εντολή», ανοίγεις το **Command Prompt** (Windows) ή το terminal.

---

## 🆕 ΜΕΡΟΣ 0 — Τι προστέθηκε σήμερα (ώστε να ξέρεις)

Πόρταρα τις τελευταίες αλλαγές σου από το WooCommerce (v1.3.3 / v1.3.4):

1. **🤖 Αυτόματη Δημιουργία Voucher** — Ρυθμίσεις → νέα ενότητα. Ενεργοποιείς + διαλέγεις status (π.χ. «Processing»). Μόλις μια παραγγελία περάσει σε αυτό το status, **δημιουργείται μόνη της** voucher (Επόμενη Μέρα, με auto BOX NOW), εφόσον δεν υπάρχει ήδη.
2. **Bug Report** — προστέθηκε υποχρεωτικό πεδίο **Email επικοινωνίας** (προ-συμπληρωμένο με το email του admin).
3. **Auto-updater** (το μεγάλο) — έτοιμος, εξηγείται στο ΜΕΡΟΣ 2.
4. **`setup.php`** — ένα script που εγκαθιστά/επιδιορθώνει τα πάντα (πίνακας, events, δικαιώματα) με ασφάλεια.

> ⚠️ **ΣΗΜΑΝΤΙΚΟ — κάνε αυτό ΠΡΩΤΑ τη Δευτέρα:** Άνοιξε το XAMPP (Apache + MySQL) και τρέξε **μία φορά**:
> ```
> C:\xampp\php\php.exe C:\xampp\htdocs\opencart4\extension\couriercenter\setup.php
> ```
> Αυτό καταχωρεί τα νέα events (auto-create, BOX NOW) + δικαιώματα. Χωρίς αυτό, το auto-create και ο updater **δεν** θα δουλέψουν (το MySQL ήταν κλειστό όταν τα έφτιαξα).

---

## 🐙 ΜΕΡΟΣ 1 — Ανέβασμα στο GitHub

### 1.1 Εγκατάσταση Git (μία φορά)
1. Κατέβασε από **https://git-scm.com/download/win** και κάνε install (όλα Next/προεπιλογές).
2. Έλεγχος: άνοιξε Command Prompt και γράψε `git --version` → πρέπει να δείξει έκδοση.

### 1.2 Λογαριασμός & Repository
1. Φτιάξε λογαριασμό στο **https://github.com** (αν δεν έχεις).
2. Πάνω δεξιά **+ → New repository**.
   - **Repository name:** `courier-center-opencart`
   - **Visibility:** *Public* (για να μπορεί να το κατεβάζει όποιος θέλει).
   - **ΜΗΝ** βάλεις τικ σε «Add a README» (θα τα ανεβάσουμε εμείς).
   - **Create repository**.
3. Κράτα το URL που σου δείχνει, π.χ. `https://github.com/TO_USERNAME_SOU/courier-center-opencart.git`

### 1.3 Δομή του repository
Το repo θα περιέχει **τα περιεχόμενα του φακέλου** `extension\couriercenter\`
(δηλαδή τους φακέλους `admin/`, `catalog/`, `library/`, και τα `install.json`, `setup.php` κ.λπ. στη ρίζα).
Έτσι δουλεύει σωστά και ο auto-updater.

### 1.4 Ανέβασμα (πρώτη φορά)
Άνοιξε Command Prompt και τρέξε **μία-μία** τις εντολές (άλλαξε το URL με το δικό σου):

```
cd C:\xampp\htdocs\opencart4\extension\couriercenter
git init
git add .
git commit -m "Courier Center for OpenCart 4 - initial release v1.0.0"
git branch -M main
git remote add origin https://github.com/TO_USERNAME_SOU/courier-center-opencart.git
git push -u origin main
```
> Την πρώτη φορά θα σου ζητήσει σύνδεση στο GitHub (άνοιγμα παραθύρου) — κάνε login.

### 1.5 Πώς το κατεβάζει κάποιος άλλος
- **Εύκολο:** Στη σελίδα του repo → πράσινο κουμπί **Code → Download ZIP**.
- **Με Git:** `git clone https://github.com/TO_USERNAME_SOU/courier-center-opencart.git`

### 1.6 Όταν κάνεις αλλαγές στο μέλλον
```
cd C:\xampp\htdocs\opencart4\extension\couriercenter
git add .
git commit -m "Περιγραφή της αλλαγής"
git push
```

---

## 🔄 ΜΕΡΟΣ 2 — AUTO-UPDATE (όπως το WooCommerce)

Έφτιαξα **ολοκληρωμένο σύστημα auto-update** που:
- ελέγχει το GitHub για νέα έκδοση,
- δείχνει ειδοποίηση «🆕 Διαθέσιμη νέα έκδοση» στις Ρυθμίσεις,
- και με **ένα κλικ** κατεβάζει + εγκαθιστά τη νέα έκδοση (με **backup** πριν, και **επαναφορά** αν κάτι πάει στραβά).

### 2.1 Ενεργοποίηση (μία φορά)
Άνοιξε το αρχείο:
```
extension\couriercenter\admin\controller\shipping\courier_center_update.php
```
Βρες τη γραμμή (κοντά στην αρχή):
```php
const GITHUB_REPO = '';
```
και βάλε το repo σου, π.χ.:
```php
const GITHUB_REPO = 'TO_USERNAME_SOU/courier-center-opencart';
```
Αποθήκευσε. (Αν θες, πες μου το username σου και το κάνω εγώ.)

### 2.2 Πώς ενημερώνεται ο πελάτης
Στο admin: **Extensions → Shippings → Courier Center → Edit** → κάτω κουμπί
**«Έλεγχος για ενημερώσεις»**.
- Αν υπάρχει νέα έκδοση → εμφανίζεται **«Ενημέρωση τώρα»** → ένα κλικ και τελείωσε.

### 2.3 Πώς ΕΣΥ δημοσιεύεις νέα έκδοση (το «release»)
Κάθε φορά που θες να βγάλεις update:

1. **Άλλαξε την έκδοση** στο `install.json`:
   ```json
   { "name": "...", "version": "1.0.1", ... }
   ```
   (πάντα μεγαλύτερο νούμερο από το προηγούμενο)

2. **Ανέβασε στο GitHub:**
   ```
   git add .
   git commit -m "v1.0.1 - περιγραφή αλλαγών"
   git push
   ```

3. **Φτιάξε Release στο GitHub** (αυτό «ενεργοποιεί» το update για όλους):
   - Σελίδα repo → δεξιά **Releases → Create a new release** (ή **Draft a new release**).
   - **Choose a tag:** γράψε `v1.0.1` → **Create new tag**.
   - **Release title:** `v1.0.1`
   - **Describe:** τι άλλαξε (αυτό εμφανίζεται στις σημειώσεις).
   - **Publish release**.

   Αυτό αρκεί — ο updater διαβάζει αυτόματα το «latest release».

   *(Προαιρετικά, για πιο «καθαρό» update: φτιάξε ένα zip που μέσα έχει φάκελο `couriercenter/` με όλα τα αρχεία, και «σύρε» το στο Release ως attachment. Αν δεν το κάνεις, ο updater χρησιμοποιεί αυτόματα τον πηγαίο κώδικα του tag — δουλεύει και έτσι.)*

### 2.4 Προσοχές (διάβασέ τα)
- **Δικαιώματα εγγραφής:** Ο updater γράφει στον φάκελο `extension/couriercenter/`. Σε σχεδόν όλα τα hosting αυτό δουλεύει. Αν βγάλει «copy failed», ο server δεν έχει write permission εκεί (ο host το φτιάχνει εύκολα).
- **Backup:** Πριν κάθε update κρατά αντίγραφο στο `system/storage/cc_backup_ΗΜΕΡΟΜΗΝΙΑ/`. Αν κάτι σπάσει, επαναφέρεται μόνο του.
- **Νέα events σε update:** Αν μια νέα έκδοση προσθέσει νέα events, τρέξε **μία φορά** το `setup.php` μετά το update (το λέει και το μήνυμα επιτυχίας).
- **Πρώτη δοκιμή μαζί:** Πριν το εμπιστευτείς σε πελάτη, ας το δοκιμάσουμε **μία φορά μαζί** σε live server (βγάζω v1.0.1 δοκιμαστικά και πατάμε «Ενημέρωση»).

---

## 🌐 ΜΕΡΟΣ 3 — Δικό σου Live Eshop με OpenCart (για test σε πραγματικό περιβάλλον)

### 3.1 Τι χρειάζεσαι από το hosting
Ζήτα (ή έλεγξε) ότι ο server έχει:
- **PHP 8.1 ή 8.2** (το OpenCart 4 το θέλει)
- **MySQL 5.7+ ή MariaDB 10.3+**
- Ενεργά PHP extensions: **cURL, ZipArchive (zip), GD, mbstring, OpenSSL, JSON**
- (Για το auto-update χρειάζονται **cURL + ZipArchive** — τα έχουν σχεδόν όλα.)

### 3.2 Επιλογές hosting (από εύκολο → πιο τεχνικό)
| Επιλογή | Κόστος | Δυσκολία | Σχόλιο |
|---|---|---|---|
| **Shared hosting με cPanel** (π.χ. Pointer, Papaki, IP.gr, Hostinger) | ~3-8€/μήνα | 🟢 Εύκολο | Έχει «Softaculous» που εγκαθιστά OpenCart με 1 κλικ. **Προτεινόμενο για test.** |
| **VPS** (DigitalOcean, Hetzner, Contabo) | ~5-10€/μήνα | 🟡 Μεσαίο | Πλήρης έλεγχος, αλλά στήνεις εσύ PHP/MySQL. |
| **Τοπικά μόνο** (το XAMPP που έχεις) | Δωρεάν | 🟢 | Δεν είναι «live» (δεν το βλέπουν άλλοι), αλλά για test αρκεί. |

> Για να έχεις **πραγματικό live test** (με domain, με το BOX NOW SDK να φορτώνει σωστά, με emails), πρότεινω **shared hosting με cPanel + Softaculous**.

### 3.3 Εγκατάσταση OpenCart 4 στο live (με cPanel/Softaculous)
1. Πάρε hosting + ένα domain (ή subdomain, π.χ. `test.todomain.gr`).
2. Μπες στο **cPanel** → ψάξε **Softaculous Apps Installer** → **OpenCart**.
3. **Install** → διάλεξε domain, βάλε admin username/password → **Install**.
   - *(Αν θες έκδοση 4.0.2.3 ακριβώς όπως τοπικά, διάλεξέ την αν υπάρχει, αλλιώς την τελευταία 4.x.)*
4. Τελείωσε — έχεις live κατάστημα + admin (`todomain.gr/admin`).

**Εναλλακτικά (χειροκίνητα, χωρίς Softaculous):**
1. Κατέβασε OpenCart από **https://www.opencart.com/index.php?route=cms/download**
2. Ανέβασε τα αρχεία στο `public_html` μέσω **File Manager** ή **FTP** (FileZilla).
3. Φτιάξε MySQL database στο cPanel (**MySQL Databases**) + χρήστη.
4. Άνοιξε `todomain.gr` στον browser → ο installer σε καθοδηγεί (βάζεις τα στοιχεία της βάσης).
5. Μετά την εγκατάσταση: **σβήσε τον φάκελο `install/`** (το λέει και το OpenCart).

### 3.4 Εγκατάσταση του Courier Center extension στο live
1. **Ανέβασε τα αρχεία:** Κατέβασε το ZIP από το GitHub (ΜΕΡΟΣ 1.5) και ανέβασε τα περιεχόμενα στον φάκελο:
   ```
   {opencart}/extension/couriercenter/
   ```
   (μέσω cPanel File Manager ή FTP). Δηλαδή να υπάρχει `extension/couriercenter/admin/`, `.../catalog/` κ.λπ.

2. **Τρέξε το setup** (εγκαθιστά πίνακα + events + δικαιώματα):
   - Αν το hosting έχει **SSH/Terminal**: `php extension/couriercenter/setup.php`
   - Αν **δεν** έχει terminal: πες μου και θα σου δώσω εναλλακτικό τρόπο (μέσω admin), ή το τρέχουμε μαζί.

3. **Ενεργοποίηση στο admin:** **Extensions → Extensions → (τύπος) Shipping → Courier Center → Install** (το πράσινο +), μετά **Edit** για ρυθμίσεις.

4. **Ρυθμίσεις:** Βάλε τα **API credentials**, πάτα **Test & Auto-fill** για στοιχεία αποστολέα, και ρύθμισε κόστος/BOX NOW/auto-create όπως τοπικά.

5. **Cron (αυτόματο status):** Στο cPanel → **Cron Jobs** → πρόσθεσε κάθε 2 ώρες:
   ```
   php /home/USER/public_html/extension/couriercenter/cron_status_tracker.php
   ```
   *(το ακριβές path το βρίσκεις στο File Manager)*

> ⚠️ **Προσοχή στο table prefix:** Αν στην εγκατάσταση του OpenCart άφησες το προεπιλεγμένο prefix `oc_`, όλα παίζουν. Αν έβαλες άλλο prefix, το `setup.php` το διορθώνει αυτόματα στον πίνακα — αλλά πες μου να το επιβεβαιώσουμε.

---

## ✅ ΜΕΡΟΣ 4 — Checklist για τη Δευτέρα

- [ ] Άνοιξε XAMPP (Apache + MySQL).
- [ ] Τρέξε `php extension\couriercenter\setup.php` (καταχωρεί νέα events/δικαιώματα).
- [ ] Δοκίμασε **🤖 Auto-create**: Ρυθμίσεις → ενεργοποίησε + διάλεξε «Processing» → άλλαξε μια παραγγελία (χωρίς voucher) σε Processing → πρέπει να φτιαχτεί voucher μόνο του (δες στην καρτέλα Courier Center + History).
- [ ] Φτιάξε GitHub λογαριασμό + repo (ΜΕΡΟΣ 1).
- [ ] Ανέβασε τον κώδικα (ΜΕΡΟΣ 1.4).
- [ ] Πες μου το GitHub username → βάζω το `GITHUB_REPO` και δοκιμάζουμε το auto-update μαζί.
- [ ] (Όποτε θες) Πάρε hosting + στήσε το live eshop (ΜΕΡΟΣ 3).

---

### 📞 Όταν γυρίσεις
Πες μου: **(α)** το GitHub username σου, **(β)** αν πήρες hosting και ποιο.
Θα ολοκληρώσω το auto-update (1 γραμμή) και θα στήσουμε μαζί το live test.
