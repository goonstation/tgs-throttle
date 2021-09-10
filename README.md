# TGS Throttle

This is a script designed to limit the maximum amount of compilations that TGS can trigger at once. It does this by more-or-less replicating what TGS does in its own automatic-update logic, but with additional constraints on concurrent jobs.

## Setup

- Clone this directory somewhere.
- Copy `config.example.ini` to `config.ini` and fill with credentials.
- Run `composer install`.
- Disable the built-in automatic-update functionality in TGS.

## Usage

- Set up a cronjob to run `php run.php` on a small-ish interval (e.g. 2 minutes)
- Probably run the script as the same user that runs TGS

### FAQ

**Q: Can't I just set the update interval time for each TGS instance to a different value?**

**A:** Ish, but those intervals can drift over time, and on systems where CPU resources are either limited or in-use, the potential for a large amount of compilations triggering at once can result in significant server slowdown.

**Q: Why oh why is this written in PHP?**

**A:** Because I wanted to and literally no one could stop me.
