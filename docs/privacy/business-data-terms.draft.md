# Data processing terms for businesses (draft)

> Draft for counsel, to attach to the business terms of service. It follows the split decided in roles.md: the
> organization is the controller of its loyalty program, punchcard its processor.

1. **Roles.** For its loyalty program (cards, stamps, rewards, offers to customers who follow it) the organization is the
   controller and punchcard its processor. punchcard is the controller of customer accounts, sign-in, fraud prevention
   and its own news.
2. **Instructions.** punchcard processes program data only to run the program through the app, as the organization
   configures it, and to keep stamps genuine (fraud prevention).
3. **Franchise networks.** The franchisor (organization) is the controller of the shared program and sees all its
   members. A franchisee only sees customers who stamped with it in person, never another franchisee's customers, staff,
   stampers or figures. Staff at any site of the network see a customer's progress while the customer is at their
   counter.
4. **Staff.** The organization makes sure its owners and staff only use customer data for the program, and removes staff
   who leave (they lose access at once).
5. **Confidentiality and security.** punchcard staff with access are bound to confidentiality; security measures as in
   the register (encryption in transit, tenant isolation, audit log, no personal data in logs).
6. **Sub-processors.** punchcard uses the sub-processors listed in the privacy notice and tells the organization before
   adding one.
7. **Customers' rights.** Customers exercise their rights in the app (download, rectify, withdraw consent, delete).
   punchcard forwards any other request about a program to the organization and helps it answer.
8. **Breaches.** punchcard tells the organization without undue delay of any breach affecting its program data.
9. **End of the contract.** When an organization leaves, its program data is deleted or anonymised; customers keep their
   punchcard account.
10. **Location and transfers.** The database, files and backups are hosted in Morocco, at Oracle Cloud in Casablanca
    (ADR 0008). Sub-processors abroad, as declared to the CNDP: Cloudflare carries all traffic, decrypted, without
    storing it as a database; Resend (email) keeps addresses, names, the messages, and their logs and metadata in
    the United States; Sentry (error monitoring, EU region in Germany) keeps a user id and the request's context when an error
    happens. Backups are kept 30 days.
11. **Declaration.** The organization declares its program to the CNDP; punchcard provides the content of the
    declaration (the program entries of the register).
