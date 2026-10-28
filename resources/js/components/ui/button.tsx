import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-button text-sm font-medium transition-[color,box-shadow] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-xs hover:bg-primary/90",
        destructive:
          "bg-destructive text-white dark:text-background shadow-xs hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
        outline:
          "border border-input bg-card shadow-xs hover:bg-accent hover:text-accent-foreground",
        secondary:
          "bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80",
        ghost: "hover:bg-accent hover:text-accent-foreground",
        "ghost-destructive":
          "text-destructive hover:bg-destructive/10 hover:text-destructive",
        link: "text-primary underline-offset-4 hover:underline",
        // An outline on a primary (espresso) surface: the impersonation bar's Back to admin (CHW-144).
        "on-primary":
          "border border-primary-foreground text-primary-foreground hover:bg-primary-foreground/10",
        // Saffron, for the action that gives stamps (CHW-30, S2: "Add 2 stamps"); stamps and rewards only.
        stamp: "bg-stamp text-stamp-foreground shadow-xs hover:bg-stamp/90",
      },
      size: {
        // 44 px controls take radius 12; the 56 px lg keeps the button radius, 14 (punchcard-ui.css .btns, .btn).
        default: "h-11 rounded-md px-5 py-2 has-[>svg]:px-4",
        sm: "h-9 rounded-md px-3 has-[>svg]:px-2.5",
        // At least 56px, wrapping a long label (French) instead of spilling past the button.
        lg: "h-auto min-h-14 px-7 py-3 text-lg whitespace-normal text-balance has-[>svg]:px-5",
        icon: "size-11 rounded-md",
        // Counter mode (CHW-29, S1): 72 px targets, read at arm's length in a morning rush.
        counter: "min-h-18 rounded-2xl px-6 font-display text-2xl font-bold tabular-nums",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
