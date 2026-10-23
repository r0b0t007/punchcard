# Controllers and processors (draft)

Who decides why and how personal data is processed (the controller) and who processes it on their behalf (the
processor), per [processing activity](processing-register.md). It follows the tenancy model of ADR 0006: an
organization (an independent café, a chain, or a franchise network) runs the loyalty program; its businesses are the
sites that stamp.

## Split (decided)

| Activity                                 | Controller        | punchcard is          |
| ---------------------------------------- | ----------------- | --------------------- |
| T1 Customer accounts and sign-in         | punchcard         | Controller            |
| T2 Loyalty programs                      | The organization  | Processor             |
| T3 Tap verification and fraud prevention | punchcard         | Controller            |
| T4 punchcard news                        | punchcard         | Controller            |
| T5 Offers from followed programs         | The organization  | Processor             |
| T6 Business users and administration     | punchcard         | Controller            |
| T7 Wallet passes and notifications       | Both, per purpose | Controller, processor |
| T8 Transactional email                   | punchcard         | Controller            |

A customer has **one** punchcard account and joins **many** programs. The account (T1) is punchcard's; what a customer
does in a program (T2, T5) belongs to that program.

## Inside a franchise network

- **The organization (franchisor) is the controller** of the shared card program. Its admins see every member of the
  program.
- **A franchisee sees only customers who stamped with it in person** (a tap, a scanned QR, or a stamp by its staff):
  their first name, their progress, their visits there. It never sees a customer who only visited another franchisee,
  nor another franchisee's staff, stampers or figures. Enforced by the tenant scopes and their tests (ADR 0006).
- **Staff at any participating site** see a customer's progress on the shared card while the customer is at the
  counter, which they need to stamp and hand rewards over. They don't get a list.
- **Marketing audiences** of a franchisee include only its own customers who follow the program (T5).
- **punchcard** processes program data only on the organization's instructions, through the app; it uses it for its own
  purposes only for T3 (fraud and integrity).

## Who files

Decided: the split above. Under Law 09-08 each controller declares its own processing, so **every organization files
its own declaration** for its loyalty program (T2, T5, and in Phase 2 the offers it sends through Wallet passes and
notifications, T7), with punchcard named as processor, and punchcard files for T1, T3, T4, T6, T8 and the pass service
itself (T7). To keep that light for a small café, punchcard provides the content of the organization's
declaration (the program entries of the register) and the business terms say so.

Not chosen: punchcard as the sole controller of everything (one declaration, but all the responsibility on punchcard)
or joint controllers (a joint-controller agreement with every organization).
