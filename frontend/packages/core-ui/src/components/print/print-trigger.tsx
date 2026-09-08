import { Printer } from 'lucide-react';

import { Button, type ButtonProps } from '../ui/button';

export interface PrintTriggerProps extends Omit<ButtonProps, 'onClick'> {
  onPrint: () => void;
  label?: string;
}

/** A toolbar button for Quick Print — pair with `useQuickPrint()`'s `print` function. */
export function PrintTrigger({ onPrint, label = 'Print', variant = 'outline', size = 'sm', ...props }: PrintTriggerProps) {
  return (
    <Button variant={variant} size={size} onClick={onPrint} {...props}>
      <Printer className="h-4 w-4" />
      {label}
    </Button>
  );
}
