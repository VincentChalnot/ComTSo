# TODO

## Verify / decide
- Check the 10 shortened attachments by eye (#9, 10, 12, 14, 20, 27, 28, 29, 32, 37).
- Accepted loss: 6 undecodable attachments (#2, 4, 5, 11, 13, 36) and comment 842 only partly recovered.
- Profile visibility option (decision D1).

## Ops
- Owner: test the `www.comtso.fr` redirect (`compose.www-redirect.yaml`) and the Keres CSP.
- First real GitHub Actions run end to end; make the `comtso-base` package public.
- Rehearse the server rollback (stop new `app`, start old `nginx`).
- Verify the NAS backup of the new stack (`new-forum/` mirror).
- Sentry: plan in `docs/deployment-checklist.md` section 11.7.
- Optional: Dependabot, ParaTest, a prod-image smoke test in CI, ghcr cleanup of old tags.

## Emails
- Dark mode for emails.
- A digest-sample email test locking the layout reuse contract.

## New features (requested by the owner)
- Change avatar.
- Quick photo rotation for the owner and admins.
- Admin panel with datagrids for every important entity (sidus/admin-bundle + sidus/datagrid-bundle).
- Statistics panel: messages per person per year, with charts.
- Admins can delete empty forums.
- Paginate search; results by type in tabs instead of end to end.
- "Les Gens" page: show ALL users, including disabled ones.
- Better logo with brand's colours (dark)
- Switch to modern UTF-8 emojis for new messages, leave the old ones
- Adding new quotes (when hovering a quote: a "Ajouter une citation" button should appear below)
