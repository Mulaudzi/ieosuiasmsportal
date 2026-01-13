import { useState, useEffect } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  CreditCard,
  Building2,
  FileText,
  ArrowRight,
  ArrowLeft,
  Copy,
  Check,
  Loader2,
  Wallet,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { toast } from "@/hooks/use-toast";
import { buyCredits, handleApiError } from "@/lib/api";

interface BuyCreditsModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onPurchaseComplete?: () => void;
  selectedCredits?: number;
  selectedPrice?: number;
}

const paymentMethods = [
  {
    id: "payfast",
    name: "PayFast",
    description: "Credit/Debit Card",
    icon: CreditCard,
  },
  {
    id: "paystack",
    name: "Paystack",
    description: "Cards & Mobile Money",
    icon: Wallet,
  },
  {
    id: "ozow",
    name: "Ozow",
    description: "Instant EFT",
    icon: Building2,
  },
  {
    id: "eft",
    name: "EFT / Invoice",
    description: "Manual Bank Transfer",
    icon: FileText,
  },
];

const bankDetails = {
  bankName: "First National Bank (FNB)",
  accountName: "IEOSUIA (Pty) Ltd",
  accountNumber: "62123456789",
  branchCode: "250655",
  reference: "SMS-",
};

export function BuyCreditsModal({
  open,
  onOpenChange,
  onPurchaseComplete,
  selectedCredits = 0,
  selectedPrice = 0,
}: BuyCreditsModalProps) {
  const [step, setStep] = useState<"order" | "payment" | "confirmation">("order");
  const [credits, setCredits] = useState(selectedCredits || 500);
  const [selectedPayment, setSelectedPayment] = useState<string | null>(null);
  const [isProcessing, setIsProcessing] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);
  const [paymentReference, setPaymentReference] = useState<string>("");
  const [eftBankDetails, setEftBankDetails] = useState<any>(null);

  // Update credits when selectedCredits prop changes
  useEffect(() => {
    if (selectedCredits > 0) {
      setCredits(selectedCredits);
    }
  }, [selectedCredits]);

  const pricePerSms = 0.27;
  const vatRate = 0.15;
  const subtotal = credits * pricePerSms;
  const vat = subtotal * vatRate;
  const total = subtotal + vat;

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text);
    setCopied(field);
    setTimeout(() => setCopied(null), 2000);
    toast({
      title: "Copied!",
      description: `${field} copied to clipboard`,
    });
  };

  const handleProceedToPayment = () => {
    if (credits < 100) {
      toast({
        title: "Minimum order",
        description: "Minimum order is 100 credits",
        variant: "destructive",
      });
      return;
    }
    setStep("payment");
  };

  const handleCompletePurchase = async () => {
    if (!selectedPayment) {
      toast({
        title: "Select payment method",
        description: "Please select a payment method to continue",
        variant: "destructive",
      });
      return;
    }

    setIsProcessing(true);

    try {
      const response = await buyCredits({
        amount: total,
        payment_method: selectedPayment,
      });

      if (response.success && response.data) {
        const { payment_url, bank_details, reference } = response.data;
        setPaymentReference(reference);

        if (selectedPayment === "eft") {
          // For EFT, show bank details
          setEftBankDetails(bank_details);
          toast({
            title: "Order placed!",
            description: "Please complete the EFT payment. Credits will be added once payment is confirmed.",
          });
          setStep("confirmation");
        } else if (payment_url) {
          // For PayFast, Paystack, Ozow - redirect to payment URL
          toast({
            title: "Redirecting to payment...",
            description: `You will be redirected to ${selectedPayment === "payfast" ? "PayFast" : selectedPayment === "paystack" ? "Paystack" : "Ozow"} to complete payment.`,
          });
          
          // Small delay to show toast, then redirect
          setTimeout(() => {
            window.location.href = payment_url;
          }, 1000);
        } else {
          // Fallback if no payment URL (shouldn't happen)
          toast({
            title: "Payment initiated",
            description: "Your payment is being processed.",
          });
          setStep("confirmation");
        }
      }
    } catch (error) {
      handleApiError(error);
    } finally {
      setIsProcessing(false);
    }
  };

  const resetModal = () => {
    setStep("order");
    setSelectedPayment(null);
    setPaymentReference("");
    setEftBankDetails(null);
    // Trigger balance refresh when closing after purchase
    onPurchaseComplete?.();
    onOpenChange(false);
  };

  const displayBankDetails = eftBankDetails || bankDetails;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-4xl p-0 overflow-hidden">
        <div className="flex flex-col md:flex-row min-h-[500px]">
          {/* Main Content */}
          <div className="flex-1 p-6">
            <DialogHeader className="mb-6">
              <DialogTitle className="text-xl">Buy Credits</DialogTitle>
            </DialogHeader>

            {/* Steps Indicator */}
            <div className="flex items-center gap-2 mb-8">
              <span className={cn("font-medium", step === "order" ? "text-primary" : "text-muted-foreground")}>
                Order
              </span>
              <ArrowRight className="h-4 w-4 text-muted-foreground" />
              <span className={cn("font-medium", step === "payment" ? "text-primary" : "text-muted-foreground")}>
                Payment
              </span>
              <ArrowRight className="h-4 w-4 text-muted-foreground" />
              <span className={cn("font-medium", step === "confirmation" ? "text-primary" : "text-muted-foreground")}>
                Confirmation
              </span>
            </div>

            {/* Step 1: Order */}
            {step === "order" && (
              <div className="space-y-6">
                <div>
                  <Label htmlFor="credits">SMS Credits</Label>
                  <Input
                    id="credits"
                    type="number"
                    min={100}
                    step={100}
                    value={credits}
                    onChange={(e) => setCredits(parseInt(e.target.value) || 0)}
                    className="mt-1.5"
                  />
                  <p className="text-xs text-muted-foreground mt-1">
                    Minimum order: 100 credits
                  </p>
                </div>

                <div className="grid grid-cols-4 gap-3">
                  {[500, 1000, 5000, 10000].map((amount) => (
                    <Button
                      key={amount}
                      variant={credits === amount ? "default" : "outline"}
                      size="sm"
                      onClick={() => setCredits(amount)}
                    >
                      {amount.toLocaleString()}
                    </Button>
                  ))}
                </div>
              </div>
            )}

            {/* Step 2: Payment */}
            {step === "payment" && (
              <div className="space-y-6">
                <div>
                  <h3 className="font-medium text-foreground mb-4">Select your payment method</h3>
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    {paymentMethods.map((method) => (
                      <button
                        key={method.id}
                        onClick={() => setSelectedPayment(method.id)}
                        className={cn(
                          "p-4 rounded-xl border-2 text-center transition-all hover:shadow-md",
                          selectedPayment === method.id
                            ? "border-primary bg-primary/5"
                            : "border-border hover:border-primary/50"
                        )}
                      >
                        <method.icon className="h-6 w-6 mx-auto mb-2 text-muted-foreground" />
                        <p className="font-medium text-foreground text-sm">{method.name}</p>
                        <p className="text-xs text-muted-foreground">{method.description}</p>
                      </button>
                    ))}
                  </div>
                </div>

                {selectedPayment === "payfast" && (
                  <div className="bg-muted/50 rounded-lg p-4">
                    <div className="flex items-center gap-2 mb-2">
                      <CreditCard className="h-4 w-4 text-muted-foreground" />
                      <span className="text-sm text-muted-foreground">
                        Credit / Debit Card via PayFast
                      </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      You will be redirected to PayFast to securely complete your payment with Visa, Mastercard, or other supported cards.
                    </p>
                  </div>
                )}

                {selectedPayment === "paystack" && (
                  <div className="bg-muted/50 rounded-lg p-4">
                    <div className="flex items-center gap-2 mb-2">
                      <Wallet className="h-4 w-4 text-muted-foreground" />
                      <span className="text-sm text-muted-foreground">
                        Cards & Mobile Money via Paystack
                      </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      You will be redirected to Paystack to complete your payment using cards or mobile money.
                    </p>
                  </div>
                )}

                {selectedPayment === "ozow" && (
                  <div className="bg-muted/50 rounded-lg p-4">
                    <div className="flex items-center gap-2 mb-2">
                      <Building2 className="h-4 w-4 text-muted-foreground" />
                      <span className="text-sm text-muted-foreground">
                        Instant EFT via Ozow
                      </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      You will be redirected to Ozow to complete an instant bank transfer. Funds are verified in real-time.
                    </p>
                  </div>
                )}

                {selectedPayment === "eft" && (
                  <div className="bg-muted/50 rounded-lg p-4">
                    <div className="flex items-center gap-2 mb-2">
                      <FileText className="h-4 w-4 text-muted-foreground" />
                      <span className="text-sm text-muted-foreground">
                        Manual EFT / Bank Transfer
                      </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                      You will receive bank details to complete a manual transfer. Credits will be added within 24 hours after payment confirmation.
                    </p>
                  </div>
                )}
              </div>
            )}

            {/* Step 3: Confirmation */}
            {step === "confirmation" && (
              <div className="space-y-6">
                <div className="text-center py-4">
                  <div className="h-16 w-16 rounded-full bg-success/10 flex items-center justify-center mx-auto mb-4">
                    <Check className="h-8 w-8 text-success" />
                  </div>
                  <h3 className="text-xl font-semibold text-foreground mb-2">
                    Order Placed!
                  </h3>
                  <p className="text-muted-foreground">
                    Reference: <span className="font-mono font-medium">{paymentReference}</span>
                  </p>
                </div>

                {selectedPayment === "eft" && displayBankDetails && (
                  <div className="bg-muted/50 rounded-lg p-4">
                    <h4 className="font-medium text-foreground mb-3">Bank Details</h4>
                    <div className="space-y-3">
                      {[
                        { label: "Bank", value: displayBankDetails.bank_name || displayBankDetails.bankName },
                        { label: "Account Name", value: displayBankDetails.account_name || displayBankDetails.accountName },
                        { label: "Account Number", value: displayBankDetails.account_number || displayBankDetails.accountNumber },
                        { label: "Branch Code", value: displayBankDetails.branch_code || displayBankDetails.branchCode },
                        { label: "Reference", value: displayBankDetails.reference || paymentReference },
                      ].map((item) => (
                        <div key={item.label} className="flex items-center justify-between">
                          <span className="text-sm text-muted-foreground">{item.label}</span>
                          <div className="flex items-center gap-2">
                            <span className="text-sm font-medium">{item.value}</span>
                            <button onClick={() => handleCopy(item.value, item.label)}>
                              {copied === item.label ? (
                                <Check className="h-4 w-4 text-success" />
                              ) : (
                                <Copy className="h-4 w-4 text-muted-foreground hover:text-foreground" />
                              )}
                            </button>
                          </div>
                        </div>
                      ))}
                    </div>
                    <p className="text-xs text-muted-foreground mt-4">
                      Credits will be added to your account within 24 hours after payment confirmation.
                    </p>
                  </div>
                )}

                <Button onClick={resetModal} className="w-full">Back to Wallet</Button>
              </div>
            )}
          </div>

          {/* Order Summary Sidebar */}
          <div className="w-full md:w-80 bg-muted/30 p-6 border-t md:border-t-0 md:border-l border-border">
            <h3 className="font-semibold text-foreground mb-4">Order Summary</h3>
            
            <div className="space-y-4">
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center">
                  <CreditCard className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <p className="font-medium text-foreground">SMS Credits</p>
                  <p className="text-sm text-muted-foreground">{credits.toLocaleString()}</p>
                </div>
              </div>

              <div className="border-t border-border pt-4 space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Cost</span>
                  <div className="text-right">
                    <span className="font-medium">R {subtotal.toFixed(2)}</span>
                    <p className="text-xs text-primary">R{pricePerSms.toFixed(2)} per SMS</p>
                  </div>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">VAT (15%)</span>
                  <span className="font-medium">R {vat.toFixed(2)}</span>
                </div>
              </div>

              <div className="border-t border-border pt-4">
                <div className="flex justify-between">
                  <span className="font-semibold text-foreground">Total</span>
                  <span className="font-bold text-xl text-foreground">R {total.toFixed(2)}</span>
                </div>
              </div>

              {step === "order" && (
                <Button className="w-full gap-2" onClick={handleProceedToPayment}>
                  Proceed to Payment
                  <ArrowRight className="h-4 w-4" />
                </Button>
              )}

              {step === "payment" && (
                <div className="space-y-2">
                  <Button
                    className="w-full gap-2"
                    onClick={handleCompletePurchase}
                    disabled={!selectedPayment || isProcessing}
                  >
                    {isProcessing ? (
                      <>
                        <Loader2 className="h-4 w-4 animate-spin" />
                        Processing...
                      </>
                    ) : (
                      <>
                        Complete Purchase
                        <ArrowRight className="h-4 w-4" />
                      </>
                    )}
                  </Button>
                  <Button variant="ghost" className="w-full gap-2" onClick={() => setStep("order")}>
                    <ArrowLeft className="h-4 w-4" />
                    Back to Order
                  </Button>
                </div>
              )}

              <p className="text-xs text-muted-foreground text-center">
                Credits expire 365 days after purchase
              </p>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
