import * as React from "react"

import { cn } from "@/lib/utils"

// Email addresses, passwords, phone numbers and links read left to right, in an Arabic form too (CHW-132,
// SI Screen). A placeholder in Arabic script still sits on the right, under its label, until something is typed.
const leftToRightTypes = new Set(["email", "password", "tel", "url"])
const rightToLeftScript = /[\u0590-\u08FF]/

function Input({ className, type, placeholder, ...props }: React.ComponentProps<"input">) {
  const leftToRight = type !== undefined && leftToRightTypes.has(type)
  return (
    <input
      type={type}
      dir={leftToRight ? "ltr" : undefined}
      placeholder={placeholder}
      data-slot="input"
      className={cn(
        "border-input file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground flex h-12 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        "focus-visible:border-ring focus-visible:ring-ring focus-visible:ring-[3px]",
        "aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
        leftToRight && typeof placeholder === "string" && rightToLeftScript.test(placeholder) && "placeholder-shown:text-end",
        className
      )}
      {...props}
    />
  )
}

export { Input }
